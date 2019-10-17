<?php

namespace NSCL\WordPress\Async;

class Cron
{
    public $action        = 'wpbg_process_cron';
    public $interval      = 'wpbg_process_cron_interval';
    public $intervalLabel = '';
    public $duration      = 300; // Seconds

    /**
     * @param string $name
     * @param callable $callback
     * @param int $interval Optional. Interval in <b>seconds</b>. 5 minutes by default.
     * @param string $intervalLabel Optional.
     */
    public function __construct($name, $callback, $interval = 300, $intervalLabel = '')
    {
        $this->action        = "{$name}_cron";
        $this->interval      = "{$name}_cron_interval";
        $this->intervalLabel = $intervalLabel;
        $this->duration      = $interval;

        // Listen for cron calls
        add_action($this->action, $callback);

        // Add custom cron interval
        add_filter('cron_schedules', [$this, 'addInterval']);
    }

    public function addInterval($intervals)
    {
        $intervals[$this->interval] = [
            'interval' => $this->duration,
            'display'  => $this->intervalLabel
        ];

        return $intervals;
    }

    /**
     * @return bool
     */
    public function schedule()
    {
        if (!$this->isScheduled()) {
            return wp_schedule_event(time(), $this->interval, $this->action);
        } else {
            return true;
        }
    }

    /**
     * @return bool
     */
    public function unschedule()
    {
        $timestamp = wp_next_scheduled($this->action);

        if ($timestamp !== false) {
            return wp_unschedule_event($timestamp, $this->action);
        } else {
            return true;
        }
    }

    /**
     * @return bool
     */
    public function isScheduled()
    {
        $timestamp = wp_next_scheduled($this->action);
        return $timestamp !== false;
    }
}
