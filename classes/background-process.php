<?php

namespace NSCL\WordPress\Async;

/**
 * @see \NSCL\WordPress\Async\BatchesMethods
 * @see \NSCL\WordPress\Async\TasksMethods
 */
class BackgroundProcess
{
    use BatchesMethods, TasksMethods;

    /** @var string Action prefix. */
    public $prefix = 'wpbg';

    /**
     * @var string Action name. The length should be 35 symbols (or less if the
     * prefix is bigger). The length of option name is limited in 64 characters.
     *
     * Option name will consist of:
     *     (5) prefix "wpbg" with separator "_"
     *     (35 <=) action name of background process
     *     (5) lock option suffix "_lock"
     *     (19) WP's transient prefix "_transient_timeout_"
     */
    public $action = 'process';

    /** @var string Process name. Prefix + "_" + action name. */
    protected $name;

    /** @var int How many tasks will have each of the batches. */
    public $batchSize = 100;

    /** @var int Cron interval in <b>minutes</b>. */
    protected $cronInterval = 5;

    /** @var int Start time of current process. */
    protected $startTime = 0;

    /**
     * @var int Lock time in <b>seconds</b>.
     *
     * <i>Don't lock for too long. The process allowed to work for a long amount
     * of time. But we should use the short time for locks. If the process fail
     * with an error on some task then the progress will freeze for too long.</i>
     */
    protected $lockTime = 30;

    /** @var bool */
    protected $isAborting = false;

    /** @var \NSCL\WordPress\Async\Cron */
    protected $cron = null;

    /** @var \NSCL\WordPress\Async\ExecutionLimits */
    protected $executionLimits = null;

    /**
     * @param array $properties Optional.
     */
    public function __construct($properties = [])
    {
        if (!empty($properties)) {
            $this->setProperties($properties);
        }

        $this->name = $this->prefix . '_' . $this->action; // "wpdb_process"

        $this->startListenEvents();
    }

    /**
     * @param array $props
     */
    public function setProperties($props)
    {
        $defaults = [
            'prefix'       => $this->prefix,
            'action'       => $this->action,
            'batchSize'    => $this->batchSize,
            'cronInterval' => $this->cronInterval
        ];

        // Get rid of wrong properties
        $props = array_intersect_key($props, $defaults);

        foreach ($props as $prop => $value) {
            $this->$prop = $value;
        }
    }

    protected function startListenEvents()
    {
        // Listen for AJAX calls
        add_action('wp_ajax_' . $this->name, [$this, 'maybeHandle']);
        add_action('wp_ajax_nopriv_' . $this->name, [$this, 'maybeHandle']);

        // Listen for cron calls
        $this->cron = $this->instantiateCron();
    }

    /**
     * @param array $tasks
     * @return self
     */
    public function addTasks($tasks)
    {
        $batches = TasksBatches::createOnTasks($tasks, $this->batchSize, $this->name);
        $batches->save();

        $this->increaseBatchesTotalCount($batches->count());
        $this->increaseTasksTotalCount(count($tasks));

        return $this;
    }

    /**
     * Run the background process.
     *
     * @return \WP_Error|array The response data or WP_Error on failure.
     */
    public function run()
    {
        // Run healthchecking cron
        $this->cron->schedule();

        // Dispatch event
        $requestUrl = $this->requestUrl();
        $requestUrl = add_query_arg($this->requestQueryArgs(), $requestUrl);

        $requestArgs = $this->requestPostArgs();

        // Use AJAX handle (see startListenEvents())
        return wp_remote_post(esc_url_raw($requestUrl), $requestArgs);
    }

    /**
     * Re-run the process if it's down.
     */
    public function touch()
    {
        if (!$this->isRunning() && !$this->isEmptyQueue()) {
            // The process is down. Don't wait for the cron, restart the process
            // now
            $this->run();
        }
    }

    public function cancel()
    {
        if ($this->isRunning()) {
            update_option($this->name . '_abort', true, 'no');
        } else {
            $this->cron->unschedule();
            TasksBatches::removeAll($this->name);
        }
    }

    /**
     * @return string
     */
    public function requestUrl()
    {
        return admin_url('admin-ajax.php');
    }

    /**
     * @return array
     */
    public function requestQueryArgs()
    {
        return [
            'action'  => $this->name,
            'wpnonce' => wp_create_nonce($this->name)
        ];
    }

    /**
     * @return array The arguments for wp_remote_post().
     */
    public function requestPostArgs()
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

        if ($this->isRunning()) {
            // Background process already running
            $this->fireDie();

        } else if ($this->isEmptyQueue()) {
            // No data to process
            $this->fireDie();

        } else {
            // Have something to process...

            // Check nonce for AJAX calls
            if (wp_doing_ajax()) {
                check_ajax_referer($this->name, 'wpnonce');
            }

            // Lock immediately and don't wait until another instance will
            // spawn. At the moment we can only use the default value for lock
            // time. But later in handle() we will set the proper lock time
            $this->lock();

            // Setup limits of execution time, lock time and memory
            $this->executionLimits = $this->instantiateExecutionLimits();
            $this->executionLimits->setupLimits();

            // Save new lock time for future locks
            $this->lockTime = $this->executionLimits->lockTime;

            // Start doing tasks
            $this->handle();
        }
    }

    /**
     * Lock the process so that multiple instances can't run simultaneously.
     */
    protected function lock()
    {
        if ($this->startTime == 0) {
            $this->startTime = time();
        }

        set_transient($this->name . '_lock', microtime(), $this->lockTime);
    }

    /**
     * Unlock the process so that other instances can spawn.
     */
    protected function unlock()
    {
        delete_transient($this->name . '_lock');
    }

    /**
     * Pass each queue item to the task handler, while remaining within server
     * memory and time limit constraints.
     */
    protected function handle()
    {
        $this->beforeStart();

        do {
            $batches = TasksBatches::createFromOptions($this->name);

            foreach ($batches as $batchName => $batch) {
                foreach ($batch as $index => $workload) {
                    // Continue locking the process
                    $this->lock();

                    $response = $this->task($workload);

                    // Remove task from the batch whether it ended up
                    // successfully or not
                    $batch->removeTask($index);

                    // Add new task if the previous one returned new workload
                    if ($response !== false) {
                        $batch->addTask($response);
                        $this->increaseTasksTotalCount(1);
                    }

                    $this->taskComplete($workload, $response);

                    // No time or memory left? We need to restart the process
                    if ($this->shouldStop()) {
                        if ($batch->isFinished()) {
                            $batches->removeBatch($batchName);
                        } else {
                            $batch->save();
                        }

                        // Stop doing tasks
                        break 2;
                    }
                } // For each task

                $batches->removeBatch($batchName);
            } // For each batch
        } while (!$this->shouldStop() && !$this->isEmptyQueue());

        if ($this->isAborting()) {
            TasksBatches::removeAll($this->name);
        }

        // Unlock the process to restart it
        $this->beforeStop();
        $this->unlock();

        // Start next batch if not completed yet or complete the process
        if (!$this->isEmptyQueue()) {
            $this->run();
        } else {
            $this->afterComplete();
        }

        $this->fireDie();
    }

    protected function beforeStart() {}
    protected function beforeStop() {}

    /**
     * Override this method to perform any actions required on each queue item.
     * Return the modified item for further processing in the next pass through.
     * Or, return true just to remove the item from the queue.
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
        $this->increaseTasksCompletedCount(1);
    }

    protected function afterComplete()
    {
        if ($this->isAborting()) {
            $this->afterCancel();
        } else {
            $this->afterSuccess();
        }

        do_action($this->name . '_completed');

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
        delete_option($this->name . '_abort');
        delete_option($this->name . '_batches_total_count');
        delete_option($this->name . '_tasks_total_count');
        delete_option($this->name . '_tasks_completed_count');
    }

    /**
     * Should stop executing tasks and restart the process.
     *
     * @return bool
     */
    protected function shouldStop()
    {
        return $this->executionLimits->timeExceeded($this->startTime)
            || $this->executionLimits->memoryExceeded()
            || $this->isAborting();
    }

    /**
     * @return bool
     */
    public function isAborting()
    {
        if ($this->isAborting) {
            // No need to request option from database anymore
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
        if (get_transient($this->name . '_lock')) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @return \NSCL\WordPress\Async\Cron
     */
    protected function instantiateCron()
    {
        return new Cron(
            $this->name,
            [$this, 'maybeHandle'],
            \MINUTE_IN_SECONDS * $this->cronInterval,
            $this->intervalLabel()
        );
    }

    /**
     * @return string
     */
    protected function intervalLabel()
    {
        return sprintf(__('Every %d Minutes'), $this->cronInterval);
    }

    /**
     * @return \NSCL\WordPress\Async\ExecutionLimits
     */
    protected function instantiateExecutionLimits()
    {
        return new ExecutionLimits();
    }

    /**
     * @return self
     */
    public function basicAuth()
    {
        add_filter('http_request_args', function ($request) {
            $request['headers']['Authorization'] = 'Basic ' . base64_encode(USERNAME . ':' . PASSWORD);
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
     * @return mixed
     */
    public function __get($name)
    {
        if (in_array($name, ['name', 'startTime', 'lockTime', 'cron', 'executionLimits'])) {
            return $this->$name;
        } else {
            return false;
        }
    }
}
