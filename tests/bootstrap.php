<?php

/**
 * @file
 * Standalone PHPUnit bootstrap for running this module's unit tests.
 */

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit;

// Load the Composer autoloader.
$autoloader = __DIR__ . '/../../../vendor/autoload.php';
if (!file_exists($autoloader)) {
  $autoloader = __DIR__ . '/../../../../vendor/autoload.php';
}
if (!file_exists($autoloader)) {
  $autoloader = '/var/www/html/vendor/autoload.php';
}
require_once $autoloader;

// Load the Drupal core autoloader.
$coreAutoloader = __DIR__ . '/../../../../core/vendor/autoload.php';
if (file_exists($coreAutoloader)) {
  require_once $coreAutoloader;
}

// Register the module namespace.
spl_autoload_register(function ($class) {
  $prefix = 'Drupal\\ai_provider_universal\\';
  $base_dir = __DIR__ . '/../src/';

  $len = strlen($prefix);
  if (strncmp($prefix, $class, $len) !== 0) {
    return;
  }

  $relative_class = substr($class, $len);
  $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

  if (file_exists($file)) {
    require_once $file;
  }
});
