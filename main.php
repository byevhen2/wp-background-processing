<?php

if (!class_exists('\NSCL\WordPress\Async\BackgroundProcess')) {
    require 'includes/functions.php';
    require 'includes/polyfills.php';

    require 'classes/tasks-batch.php';
    require 'classes/tasks-batches.php';
    require 'classes/cron.php';
    require 'classes/execution-limits.php';
    require 'classes/batches-methods.php';
    require 'classes/tasks-methods.php';
    require 'classes/background-process.php';
}
