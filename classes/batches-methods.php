<?php

namespace NSCL\WordPress\Async;

/**
 * @see \NSCL\WordPress\Async\BackgroundProcess
 */
trait BatchesMethods
{
    /**
     * @param int $increment
     */
    protected function increaseBatchesTotalCount($increment)
    {
        $newTotalCount = $this->getBatchesTotalCount() + $increment;

        // Option suffix is less than lock and transient suffixes
        update_option($this->name . '_batches_total_count', $newTotalCount, 'no');
    }

    /**
     * @return int
     */
    public function getBatchesTotalCount()
    {
        return (int)get_option($this->name . '_batches_total_count', 0);
    }

    /**
     * @return int
     *
     * @global \wpdb $wpdb
     */
    public function batchesLeft()
    {
        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE `option_name` LIKE %s",
                esc_sql_underscores($this->name . '_batch_%')
            )
        );

        return (int)$count;
    }

    /**
     * @return bool
     */
    public function isEmptyQueue()
    {
        return $this->batchesLeft() == 0;
    }

    /**
     * @param int $precision Optional. 3 digits by default.
     * @return float The progress value in range [0; 100].
     */
    public function getBatchesProgress($precision = 3)
    {
        $total = $this->getBatchesTotalCount();

        if ($total > 0) {
            $left = $this->batchesLeft();
            $completed = max(0, $total - $left); // Don't get less than 0

            $progress = round($completed / $total * 100, $precision);
            $progress = min($progress, 100); // Don't exceed the value of 100
        } else {
            $progress = 100; // All of nothing done
        }

        return $progress;
    }
}
