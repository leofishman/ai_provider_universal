<?php

namespace Drupal\ai_provider_universal\Backend;

use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Base class for server backend plugins.
 */
abstract class AiServerBackendPluginBase extends PluginBase implements AiServerBackendInterface {

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
  public function getHttpHeaders(AiUniversalServerInterface $server): array {
    return [];
  }

}
