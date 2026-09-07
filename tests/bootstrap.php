<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/wpcf7-stubs.php';

// Every src/ file guards against direct access with `defined('ABSPATH') || exit;`,
// so the constant has to exist before the autoloader pulls a class in.
defined('ABSPATH') || define('ABSPATH', dirname(__DIR__) . '/tests/');

// WordPress runtime constants the plugin classes reference; defined here so unit
// tests can exercise them without loading WordPress.
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);
