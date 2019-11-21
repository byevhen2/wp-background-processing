<?php

namespace NSCL\WordPress\Async;

/**
 * @since 1.0
 */
class TasksList implements \Iterator
{
    protected $process = 'wpbg_process'; // Name of the backgorund process
    protected $name = ''; // wpbg_process_batch_bf46955b5005c1893583b64c0ff440be
    protected $tasks = [];
    protected $currentIndex = 0;
    protected $lastIndex = -1;

    /**
     * @param array $tasks
     * @param string $processName
     * @param string $batchName Optional. "" by default (generate automatically).
     */
    public function __construct($tasks, $processName, $batchName = '')
    {
        $this->process   = $processName;
        $this->tasks     = $tasks;
        $this->lastIndex = count($tasks) - 1;

        if (!empty($batchName)) {
            $this->name = $batchName;
        } else {
            $this->name = $this->generateKey();
        }
    }

    /**
     * Generate unique key based on microtime(). Queue items are given unique
     * keys so that they can be merged upon save.
     *
     * @param int $maxLength Optional. Length limit of the key. 64 by default
     *     (the maximum length of the WordPress option name).
     * @return string The key like "wpbg_process_batch_bf46955b5005c1893583b64c0ff440be".
     *
     * @see \NSCL\WordPress\Async\BatchesList::createFromOptions()
     * @see \NSCL\WordPress\Async\BatchesList::removeAll()
     */
    public function generateKey($maxLength = 64)
    {
        // bf46955b5005c1893583b64c0ff440be
        $hash = md5(microtime() . rand());
        // wpbg_process_batch_bf46955b5005c1893583b64c0ff440be
        $key = $this->process . '_batch_' . $hash;

        return substr($key, 0, $maxLength);
    }

    /**
     * @param mixed $workload
     */
    public function addTask($workload)
    {
        $this->lastIndex++;
        $this->tasks[$this->lastIndex] = $workload;
    }

    /**
     * @param int $index
     */
    public function removeTask($index)
    {
        if (isset($this->tasks[$index])) {
            // PHP does not reset the indexes when removes the item from any position
            unset($this->tasks[$index]);
        }
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->tasks);
    }

    /**
     * @return bool
     */
    public function isFinished()
    {
        return $this->count() == 0;
    }

    public function save()
    {
        update_option($this->name, $this->tasks, 'no');
    }

    public function delete()
    {
        delete_option($this->name);
    }

    public function rewind()
    {
        // Reset the indexes and get rid of "gaps" (indexes of removed items)
        $this->tasks = array_values($this->tasks);
        $this->currentIndex = 0;
        $this->lastIndex = count($this->tasks) - 1;
    }

    /**
     * @return bool
     */
    public function valid()
    {
        return $this->currentIndex <= $this->lastIndex;
    }

    /**
     * @return mixed
     */
    public function current()
    {
        return $this->tasks[$this->currentIndex];
    }

    /**
     * @return int
     */
    public function key()
    {
        return $this->currentIndex;
    }

    public function next()
    {
        // We will not have "gaps" in indexes because we'll always remove only
        // the current task
        $this->currentIndex++;
    }

    /**
     * Get value of read-only field.
     *
     * @param string $name Field name.
     * @return mixed Field value or NULL.
     */
    public function __get($name)
    {
        if (in_array($name, ['process', 'name'])) {
            return $this->$name;
        } else {
            return null;
        }
    }
}
