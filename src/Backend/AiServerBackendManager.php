<?php

namespace Drupal\ai_provider_universal\Backend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Plugin manager for server backend plugins.
 *
 * Discovers classes in Plugin/AiServerBackend of any enabled module, so other
 * modules can contribute native backends (Anthropic, Gemini, ...) without
 * patching this module.
 */
class AiServerBackendManager extends DefaultPluginManager {

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/AiServerBackend',
      $namespaces,
      $module_handler,
      AiServerBackendInterface::class,
      AiServerBackend::class,
    );
    $this->alterInfo('ai_provider_universal_server_backend_info');
    $this->setCacheBackend($cache_backend, 'ai_provider_universal_server_backend_plugins');
  }

  /**
   * Returns backend labels keyed by plugin id, for form select options.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Map of plugin id => label.
   */
  public function getOptions(): array {
    $options = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      $options[$id] = $definition['label'];
    }
    return $options;
  }

}
