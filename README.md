# WordPress Background Process
WordPress background processing library.

Inspired by [WP Background Processing](https://github.com/deliciousbrains/wp-background-processing).

## Requirements
**Minimal**:
- PHP 5.4
- WordPress 4.6

**Recommended**:
- PHP 7
- WordPress 5

## Background Process
### Properties
`public $prefix`
Process prefix / vendor prefix.

`public $action`
Should be set to a unique name.
The length of option name is limited in 191 characters (64 characters before release of WordPress 4.4). So the length should be 162 symbols, or less if the prefix is bigger. Option name will consist of:
* (5) prefix "wpbg" with separator "_"
* (162 <=) action name of background process
* (5) lock option suffix "_lock"
* (19) WP's transient prefix "_transient_timeout_"

`public $batchSize`
Tasks limit in each batch.

`public $cronInterval`
Helthchecking cron interval time in **minutes**.

`protected $lockTime`
Lock time in **seconds**.
Don't lock for too long. The process allowed to work for a long amount of time. But we should use the short time for locks. If the process fail with an error on some task then the progress will freeze for too long.

## License
The project is licensed under the [MIT License](https://opensource.org/licenses/MIT).
