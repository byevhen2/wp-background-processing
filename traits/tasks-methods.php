<?php

namespace NSCL\WordPress\Async;

/**
 * @see \NSCL\WordPress\Async\BackgroundProcess
 */
trait TasksMethods
{
    /**
     * @param int $increment
     */
    protected function increaseTasksTotalCount($increment)
    {
        $newTotalCount = $this->getTasksTotalCount() + $increment;

        // Option suffix is less than lock and transient suffixes
        update_option($this->name . '_tasks_total_count', $newTotalCount, 'no');
    }

    /**
     * @param int $increment
     */
    protected function increaseTasksCompletedCount($increment)
    {
        $newCompletedCount = $this->getTasksCompletedCount() + $increment;

        // Option suffix is less than lock and transient suffixes
        update_option($this->name . '_tasks_completed_count', $newCompletedCount, 'no');
    }

    /**
     * @return int
     */
    public function getTasksTotalCount()
    {
        return (int)get_option($this->name . '_tasks_total_count', 0);
    }

    /**
     * @return int
     */
    public function getTasksCompletedCount()
    {
        return (int)get_option($this->name . '_tasks_completed_count', 0);
    }

    /**
     * @param int $precision Optional. 3 digits by default.
     * @return float The progress value in range [0; 100].
     */
    public function getTasksProgress($precision = 3)
    {
        $total = $this->getTasksTotalCount();

        if ($total > 0) {
            $completed = $this->getTasksCompletedCount();

            $progress = round($completed / $total * 100, $precision);
            $progress = min($progress, 100); // Don't exceed the value of 100
        } else {
            $progress = 100; // All of nothing done
        }

        return $progress;
    }
}
