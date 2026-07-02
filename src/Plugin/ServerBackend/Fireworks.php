<?php

namespace Drupal\ai_provider_universal\Plugin\ServerBackend;

use Drupal\ai_provider_universal\Attribute\ServerBackend;
use Drupal\ai_provider_universal\Entity\UniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Fireworks AI server backend.
 *
 * Fireworks speaks the OpenAI protocol, so this extends the generic backend
 * and only overrides what differs: a fixed base URI (host/port on the server
 * entity are optional), Fireworks-specific capability detection for its
 * "accounts/fireworks/models/*" ids, and routing metadata (published serverless
 * pricing and context lengths) prefilled at discovery so the smart router can
 * compare local vs. Fireworks costs without manual data entry.
 *
 * Pricing follows Fireworks' parameter-count tiers for serverless inference;
 * verify against https://fireworks.ai/pricing when models change generation.
 */
#[ServerBackend(
  id: 'fireworks',
  label: new TranslatableMarkup('Fireworks AI'),
  description: new TranslatableMarkup('Fireworks AI serverless inference (api.fireworks.ai). OpenAI-compatible protocol with Fireworks-specific model metadata: pricing and context length are prefilled for smart routing.'),
)]
class Fireworks extends OpenAiCompatible {

  /**
   * Default API endpoint, used when the server entity leaves the host empty.
   */
  protected const DEFAULT_BASE_URI = 'https://api.fireworks.ai/inference/v1';

  /**
   * Routing metadata by raw-model-id substring, first match wins.
   *
   * Values: cost_input / cost_output in USD per 1M tokens (serverless list
   * prices), quality_tier 1-5, context_length in tokens.
   */
  protected const MODEL_METADATA = [
    'llama4-maverick' => [
      'cost_input' => 0.22,
      'cost_output' => 0.88,
      'quality_tier' => 4,
      'context_length' => 1000000,
    ],
    'llama4-scout' => [
      'cost_input' => 0.15,
      'cost_output' => 0.60,
      'quality_tier' => 3,
      'context_length' => 10000000,
    ],
    'llama-v3p1-405b' => [
      'cost_input' => 3.00,
      'cost_output' => 3.00,
      'quality_tier' => 4,
      'context_length' => 131072,
    ],
    'llama-v3p1-70b' => [
      'cost_input' => 0.90,
      'cost_output' => 0.90,
      'quality_tier' => 4,
      'context_length' => 131072,
    ],
    'llama-v3p1-8b' => [
      'cost_input' => 0.20,
      'cost_output' => 0.20,
      'quality_tier' => 3,
      'context_length' => 131072,
    ],
    'deepseek-r1' => [
      'cost_input' => 3.00,
      'cost_output' => 8.00,
      'quality_tier' => 5,
      'context_length' => 163840,
    ],
    'deepseek-v3' => [
      'cost_input' => 0.90,
      'cost_output' => 0.90,
      'quality_tier' => 4,
      'context_length' => 131072,
    ],
    'qwen3-235b' => [
      'cost_input' => 0.22,
      'cost_output' => 0.88,
      'quality_tier' => 4,
      'context_length' => 131072,
    ],
    'qwen3-30b' => [
      'cost_input' => 0.15,
      'cost_output' => 0.60,
      'quality_tier' => 3,
      'context_length' => 131072,
    ],
    'mixtral-8x22b' => [
      'cost_input' => 1.20,
      'cost_output' => 1.20,
      'quality_tier' => 3,
      'context_length' => 65536,
    ],
    'whisper-v3' => [
      'quality_tier' => 3,
    ],
    'flux-1' => [
      'quality_tier' => 4,
    ],
    'nomic-embed' => [
      'cost_input' => 0.008,
      'cost_output' => 0.0,
      'quality_tier' => 3,
      'context_length' => 8192,
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(UniversalServerInterface $server): string {
    // Host/port are optional for Fireworks; fall back to the public API.
    if (!$server->getHostName()) {
      return self::DEFAULT_BASE_URI;
    }
    return parent::getBaseUri($server);
  }

  /**
   * {@inheritdoc}
   *
   * Fireworks model ids look like "accounts/fireworks/models/<name>"; the
   * llama.cpp status.args / HF-repo paths never apply, so detection is purely
   * name-based with Fireworks' catalog naming.
   */
  public function detectOperationTypes(array $modelEntry): array {
    $name = strtolower($modelEntry['id']);

    if (preg_match('/flux|stable-diffusion|sdxl|playground|japanese-stable-diffusion/', $name)) {
      return ['text_to_image'];
    }
    if (preg_match('/whisper/', $name)) {
      return ['speech_to_text'];
    }
    if (preg_match('/rerank/', $name)) {
      return ['rerank'];
    }
    if (preg_match('/llama.guard|llamaguard/', $name)) {
      return ['moderation'];
    }
    if (preg_match('/embed|gte-|e5-/', $name)) {
      return ['embeddings'];
    }

    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function detectModelMetadata(array $modelEntry): array {
    $name = strtolower($modelEntry['id']);
    foreach (self::MODEL_METADATA as $needle => $metadata) {
      if (str_contains($name, $needle)) {
        return $metadata;
      }
    }
    return [];
  }

}
