<?php

namespace NSCL\WordPress\Async;

class ExecutionLimits
{
    /**
     * @var int How many time do we have (in <b>seconds</b>) before the process
     * will be terminated.
     */
    protected $executionTime = 30;
    /** @var int Maximum allowed execution time (<b>seconds</b>). */
    public $maxExecutionTime = \HOUR_IN_SECONDS; // Big enough
    /**
     * @var int The number of <b>seconds</b> before the execution time limit to
     * stop the process and not fall dawn with error.
     */
    public $timeReserve = 10;

    /** @var int Lock time in <b>seconds</b>. */
    protected $lockTime = 30;
    /** @var int Maximum allowed lock time (<b>seconds</b>). */
    public $maxLockTime = 30;

    /** @var int The maximum amount of available memory (in <b>bytes</b>). */
    protected $memoryLimit = 2000000000;
    /** @var int Maximum memory limit (<b>bytes</b>). */
    public $maxMemoryLimit = 2000000000; // 2 GB
    /**
     * @var float Limit in range (0; 1). Prevents exceeding {memoryFactor}% of
     * max memory.
     */
    public $memoryFactor = 0.9; // 90% of max memory

    /**
     * <i>It's better to lock the background process before doing this - the
     * method "needs some time". Otherwise another process may spawn and they
     * both will start to run simultaneously.</i>
     */
    public function setupLimits()
    {
        $this->limitExecutionTime();
        $this->limitLockTime();
        $this->limitMemory();
    }

    /**
     * @return bool
     */
    public function timeExceeded($startTime)
    {
        $timeLeft = $startTime + $this->executionTime - time();
        return $timeLeft <= $this->timeReserve; // N seconds in reserve
    }

    /**
     * @return bool
     */
    public function memoryExceeded()
    {
        $currentMemory = memory_get_usage(true);
        $memoryLimit = $this->memoryLimit * $this->memoryFactor;

        return $currentMemory >= $memoryLimit;
    }

    protected function limitExecutionTime()
    {
        $executionTime = (int)ini_get('max_execution_time');

        if ($executionTime === false || $executionTime === '') {
            // Sensible default. A timeout limit of 30 seconds is common on
            // shared hostings
            $executionTime = 30;
        }

        // Try to increase execution time limit
        $disabledFunctions = explode(',', ini_get('disable_functions'));

        if (!in_array('set_time_limit', $disabledFunctions)) {
            if (set_time_limit(0)) {
                // Set to 1 hour
                $executionTime = $this->maxExecutionTime;
            }
        }

        $this->executionTime = $executionTime;
    }

    protected function limitLockTime()
    {
        // The lock time should exceed the execution time (for a little)
        $lockTime = $this->executionTime + 5;

        // Don't lock the process for too long. Less than execution time? Hope
        // 30 seconds will be enough for a single task
        $this->lockTime = min($lockTime, $this->maxLockTime);
    }

    protected function limitMemory()
    {
        $memoryLimit = ini_get('memory_limit');

        // The memory is not limited?
        if (!$memoryLimit || $memoryLimit == -1) {
            // Set to 2 GB
            $memoryLimit = $this->maxMemoryLimit;
        } else {
            // Convert from format "***M" into bytes
            $memoryLimit = intval($memoryLimit) * 1024 * 1024;
        }

        $this->memoryLimit = $memoryLimit;
    }

    /**
     * Get value of read-only field.
     *
     * @param string $name Field name.
     * @return mixed
     */
    public function __get($name)
    {
        if (in_array($name, ['executionTime', 'lockTime', 'memoryLimit'])) {
            return $this->$name;
        } else {
            return false;
        }
    }
}
