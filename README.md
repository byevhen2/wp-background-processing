# WordPress Background Processing
WordPress background processing library. Process a big amount of tasks using non-blocking asynchronous worker with queueing system.

Inspired by [WP Background Processing](https://github.com/deliciousbrains/wp-background-processing).

## Requirements
**Minimal**:
- PHP 5.4
- WordPress 4.6

**Recommended**:
- PHP 7
- WordPress 5

## Background Process
```php
class BackgroundProcess
{
    public function __construct([$properties]) { ... }

    public function setProperties($properties) { ... }

    public function addTasks($tasks) { ... }

    public function run() { ... }
    public function touch([$force]) { ... }
    public function touchWhenReady([$force]) { ... }
    public function cancel() { ... }

    public function task($workload) { ... }

    public function isAborting() { ... }
    public function isInProgress() { ... }
    public function isRunning() { ... }
    public function isEmptyQueue() { ... }
    public function isCronScheduled() { ... }

    public function startTime([$allExecutions]) { ... }

    public function getProgress([$decimals]) { ... }
    public function tasksProgress([$decimals]) { ... }
    public function batchesProgress([$decimals]) { ... }

    public function getStat($parameter[, $default, $allowCache]) { ... }

    public function tasksCount([$allowCache]) { ... }
    public function tasksCompleted() { ... }
    public function tasksLeft() { ... }

    public function batchesCount() { ... }
    public function batchesCompleted() { ... }
    public function batchesLeft() { ... }

    public function basicAuth($username, $password) { ... }

    public function scheduleCron([$waitTime, $force]) { ... }
    public function unscheduleCron() { ... }
}
```

### Dispatching
* Override method `task()` and put into it any logic to perform on the queued item.
* Push items to queue with `addTasks()`.
* Run the process with `run()`.
* _(Optional)_ If your site is behind BasicAuth then you need to attach your BasicAuth credentials to requests using `basicAuth()` method.

### Properties
###### `prefix`
Process/vendor prefix.

###### `action`
Should be set to a unique name. The length of option name is limited in **191** characters (64 characters before release of WordPress 4.4). So the length should be **162** symbols, or less if the prefix is bigger. Option name will consist of:
* (5) prefix "wpbg" with separator "_";
* (162 <=) action name;
* (5) lock option suffix "_lock";
* (19) WP's transient prefix "\_transient_timeout\_".

###### `batchSize`
Tasks limit in each batch.

###### `cronInterval`
Helthchecking cron interval time in **minutes**.

###### `lockTime`
Lock time in **seconds**.

_Don't lock for too long. The process allowed to work for a long amount of time. But you should use the short time for locks. If the process fail with an error on some task then the progress will freeze for too long._

###### `maxExecutionTime`
Maximum allowed execution time in **seconds**.

###### `timeReserve`
Stop **X seconds** before the execution time limit.

###### `memoryLimit`
Max memory limit in **bytes**.

###### `memoryFactor`
The limitation for the memory usage. The range is \[0; 1\] (where 0.9 is 90%).

### Actions
* `register_{$processName}` — created another instance of the BackgroundProcess (triggers on action "init" with priority 5).
    ```php
        do_action("register_{$processName}", BackgroundProcess);
    ```
* `before_first_start_{$processName}` — on the first start of the process.
    ```php
        do_action("before_first_start_{$processName}", BackgroundProcess, int $startTime);
    ```
* `before_start_{$processName}` — each time the process starts or continues doing tasks.
    ```php
        do_action("before_start_{$processName}", BackgroundProcess);
    ```
* `before_stop_{$processName}` — when the process ready to stop (finished or should stop because of execution time or memory limitations).
    ```php
        do_action("before_stop_{$processName}", BackgroundProcess);
    ```
* `after_cancel_{$processName}` — the process cancels its execution.
    ```php
        do_action("after_cancel_{$processName}", BackgroundProcess);
    ```
* `after_success_{$processName}` — the process finished all the work.
    ```php
        do_action("after_success_{$processName}", BackgroundProcess);
    ```
* `after_complete_{$processName}` — each time the process finishes or cancels its work; triggers after actions "after_cancel" and "after_success".
    ```php
        do_action("after_complete_{$processName}", BackgroundProcess, bool $succeeded);
    ```

## License
The project is licensed under the [MIT License](https://opensource.org/licenses/MIT).
