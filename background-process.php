<?php

namespace NSCL\WordPress\Async;

if (!defined('ABSPATH')) {
    exit;
}

require 'includes/functions.php';

require 'classes/tasks-list.php';
require 'classes/batches-list.php';

/**
 * @since 1.0
 */
class BackgroundProcess
{
    /** @var string Process name: "{prefix}_{action}". */
    protected $name = 'wpbg_process';

    /** @var string The name of healthchecking cron: "{prefix}_{action}_cron" */
    protected $cronName = 'wpbg_process_cron';

    /** @var string "{prefix}_{action}_cron_interval" */
    protected $cronIntervalName = 'wpbg_process_cron_interval';

    // Properties
    public $prefix       = 'wpbg';    // Process prefix / vendor prefix
    public $action       = 'process'; // Process action name
    public $batchSize    = 100; // Tasks limit in each batch
    public $cronInterval = 5;   // Helthchecking cron interval time in MINUTES
    public $lockTime     = 30;  // Lock time in SECONDS
    public $maxExecutionTime = \HOUR_IN_SECONDS; // Maximum allowed execution time in SECONDS
    public $timeReserve  = 10;  // Stop X SECONDS before the execution time limit
    public $memoryLimit  = 2000000000; // Max memory limit in BYTES
    public $memoryFactor = 0.9; // {memoryFactor}% of available memory. Range: [0; 1]

    /**
     * @var int Helthchecking cron interval time in <b>seconds</b>:
     * $cronInterval * 60.
     */
    protected $cronTime = 300;

    /** @var int Start time of current process. */
    protected $startTime = 0;

    /**
     * @var int How many time do we have (in <b>seconds</b>) before the process
     * will be terminated.
     */
    protected $availableTime = 0;

    /** @var int The maximum amount of available memory (in <b>bytes</b>). */
    protected $availableMemory = 0;

    /** @var bool */
    protected $isAborting = false;

    /**
     * @param array $properties Optional.
     */
    public function __construct($properties = [])
    {
        if (!empty($properties)) {
            $this->setProperties($properties);
        }

        $this->name = $this->prefix . '_' . $this->action;       // "wpdb_process"
        $this->cronName = $this->name . '_cron';                 // "wpbg_process_cron"
        $this->cronIntervalName = $this->cronName . '_interval'; // "wpbg_process_cron_interval"
        $this->cronTime = \MINUTE_IN_SECONDS * $this->cronInterval;

        $this->addActions();
    }

    /**
     * @param array $properties
     */
    public function setProperties($properties)
    {
        // Get rid of non-property fields
        $availableToSet = array_flip($this->getPropertyNames());

        $properties = array_intersect_key($properties, $availableToSet);

        // Set up properties
        foreach ($properties as $property => $value) {
            $this->$property = $value;
        }
    }

    /**
     * @return array
     */
    protected function getPropertyNames()
    {
        return ['prefix', 'action', 'batchSize', 'cronInterval', 'lockTime',
            'maxExecutionTime', 'timeReserve', 'memoryLimit', 'memoryFactor'];
    }

    protected function addActions()
    {
        // Listen for AJAX calls
        add_action('wp_ajax_' . $this->name, [$this, 'maybeHandle']);
        add_action('wp_ajax_nopriv_' . $this->name, [$this, 'maybeHandle']);

        // Listen for cron events
        add_action($this->cronName, [$this, 'maybeHandle']);
        add_filter('cron_schedules', [$this, 'registerCronInterval']);
    }

    /**
     * @param array $tasks
     * @return self
     */
    public function addTasks($tasks)
    {
        $batches = BatchesList::create($this->name, $tasks, $this->batchSize);
        $batches->save();

        $this->increaseBatchesCount($batches->count());
        $this->increaseTasksCount(count($tasks));

        return $this;
    }

    /**
     * Run the background process.
     *
     * @return \WP_Error|true TRUE or WP_Error on failure.
     */
    public function run()
    {
        // Dispatch AJAX event
        $requestUrl = add_query_arg($this->requestQueryArgs(), $this->requestUrl());
        $response = wp_remote_post(esc_url_raw($requestUrl), $this->requestPostArgs());

        return is_wp_error($response) ? $response : true;
    }

    /**
     * Re-run the process if it's down.
     *
     * If you’re running into the "Call to undefined function wp_create_nonce()"
     * error, then you've hooked too early. The hook you should use is "init".
     *
     * @param bool $force Optional. Touch process even on AJAX or cron requests.
     *     FALSE by default.
     */
    public function touch($force = false)
    {
        if (!$force && (wp_doing_ajax() || wp_doing_cron())) {
            return;
        }

        if (!$this->isRunning() && !$this->isEmptyQueue()) {
            // The process is down. Don't wait for the cron and restart the process
            $this->run();
        }
    }

    /**
     * Wait for action "init" and re-run the process if it's down.
     *
     * @param bool $force Optional. Touch process even on AJAX or cron requests.
     *     FALSE by default.
     */
    public function touchWhenReady($force = false)
    {
        if (did_action('init')) {
            // Already ready
            $this->touch($force);
        } else {
            // Wait for "init" action
            add_action('init', function () use ($force) {
                $this->touch($force);
            });
        }
    }

    public function cancel()
    {
        if ($this->isRunning()) {
            $this->updateOption('abort', true);
        } else {
            $this->unscheduleCron();
            BatchesList::removeAll($this->name);
            $this->clearOptions();
        }
    }

    /**
     * @return string
     */
    protected function requestUrl()
    {
        return admin_url('admin-ajax.php');
    }

    /**
     * @return array
     */
    protected function requestQueryArgs()
    {
        return [
            'action'     => $this->name,
            'wpbg_nonce' => wp_create_nonce($this->name)
        ];
    }

    /**
     * @return array The arguments for wp_remote_post().
     */
    protected function requestPostArgs()
    {
        return [
            'timeout'   => 0.01,
            'blocking'  => false,
            'data'      => [],
            'cookies'   => $_COOKIE,
            'sslverify' => apply_filters('verify_local_ssl', false)
        ];
    }

    /**
     * Checks whether data exists within the queue and that the process is not
     * already running.
     */
    public function maybeHandle()
    {
        // Don't lock up other requests while processing
        session_write_close();

        // Check nonce of AJAX call
        if (wp_doing_ajax()) {
            check_ajax_referer($this->name, 'wpbg_nonce');

            // Nonce OK, schedule cron event. But don't run immediately, AJAX
            // handler already starting the process
            $this->scheduleCron($this->cronTime);
        }

        if (!$this->isEmptyQueue() && !$this->isRunning()) {
            // Have something to process...

            // Lock immediately or another instance may spawn before we go to
            // handle()
            $locked = $this->lock();

            if ($locked) {
                // Setup limits for execution time and memory
                $this->setupLimits();

                // Start doing tasks
                $this->handle();
            }
        }

        $this->fireDie();
    }

    /**
     * Lock the process so that multiple instances can't run simultaneously.
     *
     * @return bool TRUE if the transient was set, FALSE - otherwise.
     */
    protected function lock()
    {
        if ($this->startTime == 0) {
            $this->startTime = time();
        }

        return set_transient($this->name . '_lock', microtime(), $this->lockTime);
    }

    /**
     * Unlock the process so that other instances can spawn.
     */
    protected function unlock()
    {
        $this->startTime = 0;
        delete_transient($this->name . '_lock');
    }

    /**
     * <i>Hint: it's better to lock the background process before doing this -
     * the method "needs some time". Otherwise another process may spawn and
     * they both will start to run simultaneously and do the same tasks twice.</i>
     */
    protected function setupLimits()
    {
        $this->limitExecutionTime();
        $this->limitMemory();
    }

    protected function limitExecutionTime()
    {
        $availableTime = ini_get('max_execution_time');

        // Validate the value
        if ($availableTime === false || $availableTime === '') {
            // A timeout limit of 30 seconds is common on shared hostings
            $availableTime = 30;
        } else {
            $availableTime = intval($availableTime);
        }

        if ($availableTime <= 0) {
            // Unlimited
            $availableTime = $this->maxExecutionTime;
        } else if ($this->maxExecutionTime < $availableTime) {
            $availableTime = $this->maxExecutionTime;
        } else {
            // Try to increase execution time limit
            $disabledFunctions = explode(',', ini_get('disable_functions'));

            if (!in_array('set_time_limit', $disabledFunctions) && set_time_limit($this->maxExecutionTime)) {
                $availableTime = $this->maxExecutionTime;
            }
        }

        $this->availableTime = $availableTime;
    }

    protected function limitMemory()
    {
        $availableMemory = ini_get('memory_limit');

        // The memory is not limited?
        if (!$availableMemory || $availableMemory == -1) {
            $availableMemory = $this->memoryLimit;
        } else {
            // Convert from format "***M" into bytes
            $availableMemory = intval($availableMemory) * 1024 * 1024;
        }

        $this->availableMemory = $availableMemory;
    }

    /**
     * Pass each queue item to the task handler, while remaining within server
     * memory and time limit constraints.
     */
    protected function handle()
    {
        $this->beforeStart();

        do {
            $batches = BatchesList::create($this->name);

            foreach ($batches as $batchName => $tasks) {
                foreach ($tasks as $index => $workload) {
                    // Continue locking the process
                    $this->lock();

                    $response = $this->task($workload);

                    // Remove task from the batch whether it ended up
                    // successfully or not
                    $tasks->removeTask($index);

                    // Add new task if the previous one returned new workload
                    if (!is_bool($response) && !empty($response)) { // Skip NULLs
                        $tasks->addTask($response);
                        $this->increaseTasksCount(1, false);
                    }

                    $this->taskComplete($workload, $response);

                    // No time or memory left? We need to restart the process
                    if ($this->shouldStop()) {
                        if ($tasks->isFinished()) {
                            $batches->removeBatch($batchName);
                        } else if (!$this->isAborting) {
                            $tasks->save();
                        }

                        // Stop doing tasks
                        break 3;
                    }
                } // For each task

                $batches->removeBatch($batchName);

                $this->batchComplete($batchName, $batches);
            } // For each batch
        } while (!$this->shouldStop() && !$this->isEmptyQueue());

        if ($this->isAborting) {
            BatchesList::removeAll($this->name);
        }

        $this->beforeStop();

        // Unlock the process to restart it
        $this->unlock();

        // Start next batch if not completed yet or complete the process
        if (!$this->isEmptyQueue()) {
            $this->run();
        } else {
            $this->afterComplete();
        }
    }

    protected function beforeStart() {}
    protected function beforeStop() {}

    /**
     * Override this method to perform any actions required on each queue item.
     * Return the modified item for further processing in the next pass through.
     * Or, return true/false just to remove the item from the queue.
     *
     * @param mixed $workload
     * @return mixed TRUE if succeeded, FALSE if failed or workload for new task.
     */
    public function task($workload)
    {
        sleep(1);

        return true;
    }

    /**
     * @param mixed $workload
     * @param mixed $response
     */
    protected function taskComplete($workload, $response)
    {
        $this->increaseTasksCompleted(1);
    }

    /**
     * @param string $batchName
     * @param \NSCL\WordPress\Async\BatchesList $batches
     */
    protected function batchComplete($batchName, $batches) {
        $this->increaseBatchesCompleted(1);
    }

    protected function afterComplete()
    {
        if ($this->isAborting) {
            $this->afterCancel();
        } else {
            $this->afterSuccess();
        }

        do_action($this->name . '_completed');

        $this->unscheduleCron();
        $this->clearOptions();
    }

    protected function afterSuccess()
    {
        do_action($this->name . '_succeeded');
    }

    protected function afterCancel()
    {
        do_action($this->name . '_cancelled');
    }

    protected function clearOptions()
    {
        $this->deleteOption('abort');
        $this->deleteOption('batches_count');
        $this->deleteOption('batches_completed');
        $this->deleteOption('tasks_count');
        $this->deleteOption('tasks_completed');
    }

    /**
     * Should stop executing tasks and restart the process.
     *
     * @return bool
     */
    protected function shouldStop()
    {
        return $this->timeExceeded()
            || $this->memoryExceeded()
            || $this->isAborting();
    }

    /**
     * @return bool
     */
    protected function timeExceeded()
    {
        $timeLeft = $this->startTime + $this->availableTime - time();
        return $timeLeft <= $this->timeReserve; // N seconds in reserve
    }

    /**
     * @return bool
     */
    protected function memoryExceeded()
    {
        $memoryUsed = memory_get_usage(true);
        $memoryLimit = $this->availableMemory * $this->memoryFactor;

        return $memoryUsed >= $memoryLimit;
    }

    /**
     * @return bool
     */
    public function isAborting()
    {
        if ($this->isAborting) {
            // No need to request option value from database anymore
            return true;
        }

        $this->isAborting = (bool)get_uncached_option($this->name . '_abort', false);

        return $this->isAborting;
    }

    /**
     * @return bool
     */
    public function isInProgress()
    {
        return $this->isRunning() || !$this->isEmptyQueue();
    }

    /**
     * @return bool
     */
    public function isRunning()
    {
        return get_transient($this->name . '_lock') !== false;
    }

    /**
     * @return bool
     */
    public function isEmptyQueue()
    {
        // Don't rely on batchesLeft() here:
        //     1) the method will return cached value of the option and will not
        //        see the changes outside of the process;
        //     2) methods like touch() will not work properly if there are no
        //        values of the options "batches_count" and "batches_complete"
        //        (initial state or after the process completes).

        return !BatchesList::hasMore($this->name);
    }

    /**
     * @return bool
     */
    public function isCronScheduled()
    {
        $timestamp = wp_next_scheduled($this->cronName);
        return $timestamp !== false;
    }

    /**
     * @param int $waitTime Optional. Pause before executing the cron event. 0
     *     <b>seconds</b> by default (run immediately).
     * @return bool|null Before WordPress 5.1 function wp_schedule_event()
     *     sometimes returned NULL.
     */
    public function scheduleCron($waitTime = 0)
    {
        if (!$this->isCronScheduled()) {
            return wp_schedule_event(time() + $waitTime, $this->cronIntervalName, $this->cronName);
        } else {
            return true;
        }
    }

    /**
     * @return bool|null Before WordPress 5.1 function wp_unschedule_event()
     *     sometimes returned NULL.
     */
    public function unscheduleCron()
    {
        $timestamp = wp_next_scheduled($this->cronName);

        if ($timestamp !== false) {
            return wp_unschedule_event($timestamp, $this->cronName);
        } else {
            return true;
        }
    }

    /**
     * Callback for filter "cron_schedules".
     *
     * @param array $intervals
     * @return array
     */
    public function registerCronInterval($intervals)
    {
        $intervals[$this->cronIntervalName] = [
            'interval' => $this->cronTime,
            'display'  => sprintf(__('Every %d Minutes'), $this->cronInterval)
        ];

        return $intervals;
    }

    /**
     * @param int $decimals Optional. 0 digits by default.
     * @return float The progress value in range [0; 100].
     */
    public function tasksProgress($decimals = 0)
    {
        return $this->getProgress($this->tasksCompleted(), $this->tasksCount(), $decimals);
    }

    /**
     * @param int $decimals Optional. 0 digits by default.
     * @return float The progress value in range [0; 100].
     */
    public function batchesProgress($decimals = 0)
    {
        return $this->getProgress($this->batchesCompleted(), $this->batchesCount(), $decimals);
    }

    /**
     * @param int $completed
     * @param int $total
     * @param int $decimals
     * @return float
     */
    protected function getProgress($completed, $total, $decimals)
    {
        if ($total > 0) {
            $progress = round($completed / $total * 100, $decimals);
            $progress = min($progress, 100); // Don't exceed the value of 100
        } else {
            $progress = 100; // All of nothing done
        }

        return $progress;
    }

    /**
     * @param int $increment
     * @param bool $useCache Optional. TRUE by default.
     */
    protected function increaseTasksCount($increment, $useCache = true)
    {
        $this->updateOption('tasks_count', $this->tasksCount($useCache) + $increment);
    }

    /**
     * @param int $increment
     */
    protected function increaseTasksCompleted($increment)
    {
        $this->updateOption('tasks_completed', $this->tasksCompleted() + $increment);
    }

    /**
     * @param int $increment
     */
    protected function increaseBatchesCount($increment)
    {
        $this->updateOption('batches_count', $this->batchesCount() + $increment);
    }

    /**
     * @param int $increment
     */
    protected function increaseBatchesCompleted($increment)
    {
        $this->updateOption('batches_completed', $this->batchesCompleted() + $increment);
    }

    /**
     * @param bool $useCache Optional. TRUE by default.
     * @return int
     */
    public function tasksCount($useCache = true)
    {
        return $this->getOptionNumber('tasks_count', 0, $useCache);
    }

    /**
     * @return int
     */
    public function tasksCompleted()
    {
        return $this->getOptionNumber('tasks_completed');
    }

    /**
     * @return int
     */
    public function tasksLeft()
    {
        return $this->tasksCount() - $this->tasksCompleted();
    }

    /**
     * @return int
     */
    public function batchesCount()
    {
        return $this->getOptionNumber('batches_count');
    }

    /**
     * @return int
     */
    public function batchesCompleted()
    {
        return $this->getOptionNumber('batches_completed');
    }

    /**
     * @return int
     */
    public function batchesLeft()
    {
        return $this->batchesCount() - $this->batchesCompleted();
    }

    /**
     * @param string $option
     * @param mixed $value
     * @param string $autoload Optional. "no" by default.
     */
    protected function updateOption($option, $value, $autoload = 'no')
    {
        // Option suffix $this->name is less than lock and transient suffixes
        update_option("{$this->name}_{$option}", $value, $autoload);
    }

    /**
     * @param string $option
     * @param int $default Optional. 0 by default.
     * @param bool $useCache Optional. TRUE by default.
     * @return int
     */
    protected function getOptionNumber($option, $default = 0, $useCache = true)
    {
        if ($useCache) {
            return (int)get_option("{$this->name}_{$option}", $default);
        } else {
            return (int)get_uncached_option("{$this->name}_{$option}", $default);
        }
    }

    /**
     * @param string $option
     */
    protected function deleteOption($option)
    {
        delete_option("{$this->name}_{$option}");
    }

    /**
     * @return self
     */
    public function basicAuth($username, $password)
    {
        add_filter('http_request_args', function ($request) use ($username, $password) {
            $request['headers']['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
            return $request;
        });

        return $this;
    }

    protected function fireDie()
    {
        if (wp_doing_ajax()) {
            wp_die();
        } else {
            exit(0); // Don't call wp_die() on cron
        }
    }

    /**
     * Get value of read-only field.
     *
     * @param string $name Field name.
     * @return mixed Field value or NULL.
     */
    public function __get($name)
    {
        if (in_array($name, ['name', 'cronName', 'cronIntervalName', 'cronTime',
            'startTime', 'availableTime', 'availableMemory'])
        ) {
            return $this->$name;
        } else {
            return null;
        }
    }
}
