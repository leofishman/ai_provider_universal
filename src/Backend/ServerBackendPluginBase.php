<?php

namespace Drupal\ai_provider_universal\Backend;

use Drupal\ai_provider_universal\Entity\UniversalServerInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Base class for server backend plugins.
 */
abstract class ServerBackendPluginBase extends PluginBase implements ServerBackendInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function detectModelMetadata(array $modelEntry): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getHttpHeaders(UniversalServerInterface $server): array {
    return [];
  }

}
