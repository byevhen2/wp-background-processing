<?php

namespace NSCL\WordPress\Async;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/TasksBatches.php';

/**
 * @since 1.0
 */
class BackgroundProcess
{
    // Properties
    public $prefix       = 'wpbg';    // Process prefix / vendor prefix
    public $action       = 'process'; // Process action name. Should be less or equal to 162 characters
    public $batchSize    = 100; // Tasks limit in each batch
    public $cronInterval = 5;   // Helthchecking cron interval time in MINUTES
    public $lockTime     = 30;  // Lock time in SECONDS
    public $maxExecutionTime = \HOUR_IN_SECONDS; // Maximum allowed execution time in SECONDS
    public $timeReserve  = 10;  // Stop X SECONDS before the execution time limit
    public $memoryLimit  = 2000000000; // Max memory limit in BYTES
    public $memoryFactor = 0.9; // {memoryFactor}% of available memory. Range: [0; 1]

    /** @var string Process name: "{prefix}_{action}". */
    protected $name = 'wpbg_process';

    /** @var string The name of healthchecking cron: "{prefix}_{action}_cron" */
    protected $cronName = 'wpbg_process_cron';

    /** @var string "{prefix}_{action}_cron_interval" */
    protected $cronIntervalName = 'wpbg_process_cron_interval';

    /**
     * @var int Helthchecking cron interval time in <b>seconds</b>:
     * $cronInterval * 60.
     */
    protected $cronTime = 300;

    /** @var int The start time of the current execution. */
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
     * @var bool
     *
     * @since 1.1
     */
    protected $shouldStop = false;

    /**
     * @var \stdClass
     *
     * @since 1.1
     */
    protected $options = null;

    /**
     * @param array $properties Optional.
     */
    public function __construct($properties = [])
    {
        if (!empty($properties)) {
            $this->setProperties($properties);
        }

        $this->name             = $this->prefix . '_' . $this->action; // "wpdb_process"
        $this->cronName         = $this->name . '_cron';               // "wpbg_process_cron"
        $this->cronIntervalName = $this->cronName . '_interval';       // "wpbg_process_cron_interval"

        $this->cronTime = \MINUTE_IN_SECONDS * $this->cronInterval;

        // Register options
        $this->options                   = new \stdClass();
        $this->options->lock             = $this->name . '_lock';
        $this->options->startedAt        = $this->name . '_started_at';
        $this->options->abort            = $this->name . '_abort';
        $this->options->batchesCount     = $this->name . '_batches_count';
        $this->options->batchesCompleted = $this->name . '_batches_completed';
        $this->options->tasksCount       = $this->name . '_tasks_count';
        $this->options->tasksCompleted   = $this->name . '_tasks_completed';

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

    /**
     * Get value of read-only field.
     *
     * @param string $name Field name.
     * @return mixed Field value or NULL.
     *
     * @since 1.1 "startTime" was removed. Use method startTime() instead.
     */
    public function __get($name)
    {
        if (in_array($name, ['name', 'cronName', 'cronIntervalName', 'cronTime',
            'availableTime', 'availableMemory'])
        ) {
            return $this->$name;
        } else {
            return null;
        }
    }

    protected function addActions()
    {
        // Notify the environment about the new process
        add_action('init', [$this, 'registerProcess']);

        // Listen for AJAX calls
        add_action('wp_ajax_' . $this->name, [$this, 'maybeHandle']);
        add_action('wp_ajax_nopriv_' . $this->name, [$this, 'maybeHandle']);

        // Listen for cron events
        add_action($this->cronName, [$this, 'maybeHandle']);

        // Add cron interval
        add_filter('cron_schedules', function ($intervals) {
            $intervals[$this->cronIntervalName] = [
                'interval' => $this->cronTime,
                'display'  => sprintf(__('Every %d Minutes'), $this->cronInterval)
            ];

            return $intervals;
        });
    }

    /**
     * Callback for action "init".
     *
     * @since 1.1
     */
    public function registerProcess()
    {
        $this->triggerEvent('register', $this);
    }

    /**
     * @param array $tasks
     * @return self
     */
    public function addTasks($tasks)
    {
        $batches = TasksBatches::create($this->name, $tasks, $this->batchSize);
        $batches->saveAll();

        $this->statIncrease('batchesCount', $batches->count());
        $this->statIncrease('tasksCount', count($tasks));

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
        if (!$force && ($this->isDoingAjax() || $this->isDoingCron())) {
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
            $this->updateOption($this->options->abort, true);
        } else {
            $this->unscheduleCron();
            TasksBatches::removeAll($this->name);
            $this->deleteOptions();
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
        if ($this->isDoingAjax()) {
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

        return set_transient($this->options->lock, microtime(), $this->lockTime);
    }

    /**
     * Unlock the process so that other instances can spawn.
     */
    protected function unlock()
    {
        $this->startTime = 0;
        delete_transient($this->options->lock);
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
        $this->triggerEvent('before_start', $this); // beforeStart()

        do {
            $this->handleQueue();
        } while (!$this->shouldStop() && !$this->isEmptyQueue());

        if ($this->isAborting()) {
            TasksBatches::removeAll($this->name);
        }

        $this->triggerEvent('before_stop', $this); // beforeStop()
        $this->unlock();

        // Start new session if not completed yet or complete the process
        if (!$this->isEmptyQueue()) {
            $this->run();
        } else {
            $this->triggerEvent('after_complete', $this, !$this->isAborting()); // afterComplete()
        }
    }

    /**
     * @since 1.1
     */
    protected function handleQueue()
    {
        $batches = TasksBatches::create($this->name);

        foreach ($batches as $batchName => $tasks) {
            foreach ($tasks as &$workload) {
                // Continue locking the process
                $this->lock();

                $response = $this->task($workload);

                // Remove the task from the list whether it ended up
                // successfully or not
                array_shift($tasks);

                // Add new task if the previous one returned new workload
                if (!is_bool($response) && !empty($response)) { // Skip NULLs
                    $tasks[] = $response;

                    // Don't use the cache here in the case someone added new
                    // tasks
                    $this->statIncrease('tasksCount', 1, false);
                }

                $this->taskComplete($workload, $response);

                // No time or memory left? We need to restart the process
                if ($this->shouldStop()) {
                    break;
                }
            } // For each task

            unset($workload);

            if (empty($tasks)) {
                // All tasks in the batch are finished
                $batches->removeBatch($batchName);
                $this->batchComplete($batchName, $batches);
            } else if (!$this->isAborting()) {
                // Restarting
                $batches->saveCurrent($tasks);
            }

            if ($this->shouldStop()) {
                break;
            }
        } // For each batch
    }

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
        $this->statIncrease('tasksCompleted', 1);
    }

    /**
     * @param string $batchName
     * @param \NSCL\WordPress\Async\TasksBatches $batches
     */
    protected function batchComplete($batchName, $batches)
    {
        $this->statIncrease('batchesCompleted', 1);
    }

    /**
     * It's an event handler of "before_start".
     *
     * @see BackgroundProcess::triggerEvent()
     */
    protected function beforeStart()
    {
        // Save the time of the first start
        $startedAt = (int)$this->getOption($this->options->startedAt, 0);

        if ($startedAt == 0) {
            $this->updateOption($this->options->startedAt, $this->startTime);

            // Notify about the first start
            $this->triggerEvent('before_first_start', $this, $this->startTime);
        }
    }

    /**
     * It's an event handler of "after_complete".
     *
     * @see BackgroundProcess::triggerEvent()
     */
    protected function afterComplete()
    {
        // This will trigger do_action("after_cancel") or do_action("after_success")
        // before do_action("after_complete")
        if ($this->isAborting()) {
            $this->triggerEvent('after_cancel', $this); // afterCancel()
        } else {
            $this->triggerEvent('after_success', $this); // afterSuccess()
        }

        $this->unscheduleCron();
        $this->deleteOptions();
    }

    /**
     * @since 1.1 (previously known as clearOptions())
     */
    protected function deleteOptions()
    {
        // Delete every registered option, even the custom ones
        foreach ($this->options as $option => $_) {
            delete_option($option);
        }
    }

    /**
     * Should stop executing tasks and restart the process.
     *
     * @return bool
     */
    protected function shouldStop()
    {
        if ($this->shouldStop) {
            // No need to repeat any check anymore
            return true;
        }

        $this->shouldStop = $this->timeExceeded() || $this->memoryExceeded() || $this->isAborting();

        return $this->shouldStop;
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

        $this->isAborting = (bool)$this->getOption($this->options->abort, false, false);

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
        return get_transient($this->options->lock) !== false;
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

        return !TasksBatches::hasMore($this->name);
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
     * @param bool $allExecutions Optional. Get the time of the first start.
     *     FALSE by default (get the time of the current execution).
     * @return int Unix timestamp.
     *
     * @since 1.1
     */
    public function startTime($allExecutions = false)
    {
        if (!$allExecutions) {
            return $this->startTime;
        } else {
            return (int)$this->getOption($this->options->startedAt, 0);
        }
    }

    /**
     * An alias of tasksProgress().
     *
     * @param int $decimals Optional. 0 digits by default.
     * @return float The progress value in range [0; 100].
     *
     * @since 1.1
     */
    public function getProgress($decimals = 0)
    {
        return $this->tasksProgress($decimals);
    }

    /**
     * @param int $decimals Optional. 0 digits by default.
     * @return float The progress value in range [0; 100].
     */
    public function tasksProgress($decimals = 0)
    {
        return $this->calcProgress($this->getStat('tasksCompleted'), $this->getStat('tasksCount'), $decimals);
    }

    /**
     * @param int $decimals Optional. 0 digits by default.
     * @return float The progress value in range [0; 100].
     */
    public function batchesProgress($decimals = 0)
    {
        return $this->calcProgress($this->getStat('batchesCompleted'), $this->getStat('batchesCount'), $decimals);
    }

    /**
     * @param int $completed
     * @param int $total
     * @param int $decimals
     * @return float
     *
     * @since 1.1 (previously known as getProgress())
     */
    protected function calcProgress($completed, $total, $decimals)
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
     * @param string $parameter Parameter like "batchesCount" or "tasksCompleted".
     * @param int $default Optional. 0 by default.
     * @param bool $allowCache Optional. The cache is allowed when getting the
     *     option value. TRUE by default.
     * @return int
     *
     * @since 1.1
     */
    public function getStat($parameter, $default = 0, $allowCache = true)
    {
        $option = $this->options->$parameter;
        return (int)$this->getOption($option, $default, $allowCache);
    }

    /**
     * @param string $parameter Parameter like "batchesCount" or "tasksCompleted".
     * @param int $increase
     * @param bool $allowCache Optional. The cache is allowed when getting the
     *     option value. TRUE by default.
     *
     * @since 1.1
     */
    protected function statIncrease($parameter, $increase, $allowCache = true)
    {
        $option = $this->options->$parameter;
        $this->updateOption($option, $this->getStat($parameter, 0, $allowCache) + $increase);
    }

    /**
     * @param bool $allowCache Optional. TRUE by default.
     * @return int
     *
     * @since 1.1 the argument $useCache was renamed to $allowCache.
     */
    public function tasksCount($allowCache = true)
    {
        return $this->getStat('tasksCount', 0, $allowCache);
    }

    /**
     * @return int
     */
    public function tasksCompleted()
    {
        return $this->getStat('tasksCompleted');
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
        return $this->getStat('batchesCount');
    }

    /**
     * @return int
     */
    public function batchesCompleted()
    {
        return $this->getStat('batchesCompleted');
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
     */
    protected function updateOption($option, $value)
    {
        update_option($option, $value, false);
    }

    /**
     * @param string $option
     * @param mixed $default Optional. FALSE by default.
     * @param bool $allowCache Optional. TRUE by default.
     * @return mixed
     *
     * @global \wpdb $wpdb
     *
     * @since 1.1 the argument $useCache was renamed to $allowCache.
     */
    protected function getOption($option, $default = false, $allowCache = true)
    {
        global $wpdb;

        if ($allowCache) {
            return get_option($option, $default);
        } else {
            // The code partly from function get_option()
            $suppressStatus = $wpdb->suppress_errors(); // Set to suppress errors and
                                                        // save the previous state

            $query = "SELECT `option_value` FROM {$wpdb->options} WHERE `option_name` = %s LIMIT 1";
            $row   = $wpdb->get_row($wpdb->prepare($query, $option));

            $wpdb->suppress_errors($suppressStatus);

            if (is_object($row)) {
                return maybe_unserialize($row->option_value);
            } else {
                return $default;
            }
        }
    }

    /**
     * @param string $action
     * @param mixed $_ Additional action arguments.
     *
     * @since 1.1
     */
    protected function triggerEvent($action, $_ = null)
    {
        // Get the name of the callback. For example: "afterComplete" for action
        // "after_complete"
        $parts = explode('_', $action);
        $parts = array_map('ucfirst', $parts);

        $callback = lcfirst(implode('', $parts));

        // Trigger own handler first
        if (method_exists($this, $callback)) {
            $args = func_get_args();
            array_shift($args); // Remove the action string from arguments

            // Some methods are protected, so you can't just call:
            //     call_user_func_array([$this, $callback], $args) <- Fail
            // But it also not very cool to put an array to every handler
            switch (count($args)) {
                case 1: $this->$callback($args[0]); break;
                case 2: $this->$callback($args[0], $args[1]); break;
                case 3: $this->$callback($args[0], $args[1], $args[2]); break;
                default: $this->$callback($args); break;
            }
        }

        // Trigger WordPress action
        $event = func_get_args();
        $event[0] .= '_' . $this->name; // ["{action}_{process}", ...args]

        call_user_func_array('do_action', $event);
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

    /**
     * @param int $waitTime Optional. Pause before executing the cron event. 0
     *     <b>seconds</b> by default (run immediately).
     * @param bool $force Optional. Reschedule cron even if it was already
     *     scheduled. FALSE by default.
     * @return bool|null Before WordPress 5.1 function wp_schedule_event()
     *     sometimes returned NULL.
     *
     * @since 1.1 added new argument - $force.
     */
    public function scheduleCron($waitTime = 0, $force = false)
    {
        $scheduled = $this->isCronScheduled();

        if (!$scheduled || $force) {
            if ($scheduled) {
                $this->unscheduleCron();
            }

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
     * @return bool
     */
    protected function isDoingAjax()
    {
        if (function_exists('wp_doing_ajax')) {
            return wp_doing_ajax(); // Since WordPress 4.7
        } else {
            return apply_filters('wp_doing_ajax', defined('DOING_AJAX') && DOING_AJAX);
        }
    }

    /**
     * @return bool
     */
    protected function isDoingCron()
    {
        if (function_exists('wp_doing_cron')) {
            return wp_doing_cron(); // Since WordPress 4.8
        } else {
            return apply_filters('wp_doing_cron', defined('DOING_CRON') && DOING_CRON);
        }
    }

    protected function fireDie()
    {
        if ($this->isDoingAjax()) {
            wp_die();
        } else {
            exit(0); // Don't call wp_die() on cron
        }
    }
}
