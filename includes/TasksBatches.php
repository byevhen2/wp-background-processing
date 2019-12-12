<?php

namespace NSCL\WordPress\Async;

/**
 * @since 1.1 (replaced BatchesList and TasksList)
 */
class TasksBatches implements \Iterator
{
    protected $process = 'wpbg_process'; // The name of the background process

    /**
     * @var array [Batch name => Array of tasks or NULL]. If NULL then load the
     * batch only when required (lazy loading).
     */
    protected $batches = [];

    // Fields to iterate through batches in foreach()
    protected $batchNames = [];
    protected $currentIndex = 0;
    protected $lastIndex = -1;

    /**
     * @param string $process The name of the background process.
     * @param array $batches [Batch name => Array of tasks or NULL]
     */
    public function __construct($process, $batches)
    {
        $this->process    = $process;
        $this->batches    = $batches;
        $this->batchNames = array_keys($batches);
        $this->lastIndex  = count($this->batchNames) - 1;
    }

    /**
     * @param string $batchName
     */
    public function removeBatch($batchName)
    {
        $batchIndex = array_search($batchName, $this->batchNames);

        if ($batchIndex !== false) {
            // Remove the batch from the database
            delete_option($batchName);

            // Remove the batch from the memory
            unset($this->batches[$batchName]);
            array_splice($this->batchNames, $batchIndex, 1);

            // Fix indexes
            $this->lastIndex--;

            if ($batchIndex <= $this->currentIndex) {
                $this->currentIndex--;
            }
        }
    }

    public function saveAll()
    {
        foreach ($this->batches as $batchName => $tasks) {
            update_option($batchName, $tasks, false);
        }
    }

    /**
     * @param array $tasks
     */
    public function saveCurrent($tasks)
    {
        if ($this->valid()) {
            $currentBatch = $this->key();

            $this->batches[$currentBatch] = $tasks;
            update_option($currentBatch, $tasks, false);
        }
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->batches);
    }

    /**
     * Iterator method.
     */
    public function rewind()
    {
        $this->currentIndex = 0;
    }

    /**
     * Iterator method.
     *
     * @return bool
     */
    public function valid()
    {
        return $this->currentIndex <= $this->lastIndex;
    }

    /**
     * Iterator method.
     *
     * Not supposed to check if the request (current index) is valid. You must
     * check by yourself if running current() manually (out of foreach).
     *
     * @return array
     */
    public function current()
    {
        $currentBatch = $this->key();

        $tasks = $this->batches[$currentBatch];

        // Load the batch
        if (is_null($tasks)) {
            $tasks = get_option($currentBatch, []);

            $this->batches[$currentBatch] = $tasks;
        }

        return $tasks;
    }

    /**
     * Iterator method.
     *
     * Not supposed to check if the request (current index) is valid. You must
     * check by yourself if running key() manually (out of foreach).
     *
     * @return string
     */
    public function key()
    {
        return $this->batchNames[$this->currentIndex];
    }

    /**
     * Iterator method.
     */
    public function next()
    {
        $this->currentIndex++;
    }

    /**
     * @param string $process The name of the background process.
     * @param array $tasks Optional. NULL by default.
     * @param int $batchSize Optional. 100 by default. Use only in conjunction
     *     with $tasks.
     * @return static
     *
     * @global \wpdb $wpdb
     */
    public static function create($process, $tasks = null, $batchSize = 100)
    {
        global $wpdb;

        if (is_null($tasks)) {
            // Get the batches from the database
            $query = "SELECT `option_name` FROM {$wpdb->options} WHERE `option_name` LIKE %s ORDER BY `option_id` ASC";
            $names = $wpdb->get_col($wpdb->prepare($query, "{$process}\_batch\_%")); // Escape wildcard "_"

            // [Batch name => null]
            $batches = array_combine($names, array_fill(0, count($names), null));
        } else {
            // Create batches on existing tasks
            $chunks = array_chunk($tasks, $batchSize);

            // Generate unique name for each chunk
            $batches = [];

            foreach ($chunks as $tasksChunk) {
                $batchName = static::generateName($process);
                $batches[$batchName] = $tasksChunk;
            }
        }

        return new static($process, $batches);
    }

    /**
     * Generate unique name based on microtime(). Queue items are given unique
     * names so that they can be merged upon save.
     *
     * @param string $process The name of the background process.
     * @param int $maxLength Optional. Length limit of the name. 191 by default
     *     (the maximum length of the WordPress option name since release 4.4).
     * @return string The name like "wpbg_process_batch_bf46955b5005c1893583b64c0ff440be".
     */
    public static function generateName($process, $maxLength = 191)
    {
        // bf46955b5005c1893583b64c0ff440be
        $hash = md5(microtime() . rand());
        // wpbg_process_batch_bf46955b5005c1893583b64c0ff440be
        $key = $process . '_batch_' . $hash;

        return substr($key, 0, $maxLength);
    }

    /**
     * @param string $process The name of the background process.
     * @return bool
     *
     * @global \wpdb $wpdb
     */
    public static function hasMore($process)
    {
        global $wpdb;

        $query = "SELECT COUNT(*) FROM {$wpdb->options} WHERE `option_name` LIKE %s";
        $count = (int)$wpdb->get_var($wpdb->prepare($query, "{$process}\_batch\_%")); // Escape wildcard "_"

        return $count > 0;
    }

    /**
     * @param string $process The name of the background process.
     *
     * @global \wpdb $wpdb
     */
    public static function removeAll($process)
    {
        global $wpdb;

        $query = "DELETE FROM {$wpdb->options} WHERE `option_name` LIKE %s";
        $wpdb->query($wpdb->prepare($query, "{$process}\_batch\_%")); // Escape wildcard "_"
    }
}
