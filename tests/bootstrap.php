<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit;

use Drupal\Core\KernelTests\KernelTestBase;
use Drupal\Tests\UnitTestCase;

// Cargar el autoloader de Composer.
$autoloader = __DIR__ . '/../../../vendor/autoload.php';
if (!file_exists($autoloader)) {
  $autoloader = __DIR__ . '/../../../../vendor/autoload.php';
}
if (!file_exists($autoloader)) {
  $autoloader = '/var/www/html/vendor/autoload.php';
}
require_once($autoloader);

// Cargar el autoloader de Drupal.
$coreAutoloader = __DIR__ . '/../../../../core/vendor/autoload.php';
if (file_exists($coreAutoloader)) {
  require_once($coreAutoloader);
}

// Registrar el namespace del módulo.
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
