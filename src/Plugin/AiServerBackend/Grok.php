<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Grok (xAI) server backend.
 *
 * XAI's Grok API is OpenAI-compatible. This backend provides a fixed
 * endpoint and some basic metadata for popular Grok models so they can be
 * used with smart routing.
 */
#[AiServerBackend(
  id: 'grok',
  label: new TranslatableMarkup('Grok (xAI)'),
  description: new TranslatableMarkup('Grok models from xAI (api.x.ai). OpenAI-compatible protocol.'),
)]
class Grok extends OpenAiCompatible {

  /**
   * Fixed base URI for xAI API.
   */
  protected const DEFAULT_BASE_URI = 'https://api.x.ai/v1';

  /**
   * Basic metadata for common Grok models.
   *
   * Approximate pricing in USD per 1M tokens.
   */
  protected const MODEL_METADATA = [
    'grok-2' => [
      'cost_input' => 2.00,
      'cost_output' => 10.00,
      'quality_tier' => 5,
      'context_length' => 128000,
    ],
    'grok-beta' => [
      'cost_input' => 5.00,
      'cost_output' => 15.00,
      'quality_tier' => 5,
      'context_length' => 128000,
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(AiUniversalServerInterface $server): string {
    // xAI always uses the fixed endpoint (host field is ignored).
    return self::DEFAULT_BASE_URI;
  }

  /**
   * {@inheritdoc}
   */
  public function detectModelMetadata(array $modelEntry): array {
    $id = strtolower($modelEntry['id'] ?? '');

    foreach (self::MODEL_METADATA as $pattern => $meta) {
      if (str_contains($id, $pattern)) {
        return $meta;
      }
    }

    // Fallback to parent (heuristic detection).
    return parent::detectModelMetadata($modelEntry);
  }

}
