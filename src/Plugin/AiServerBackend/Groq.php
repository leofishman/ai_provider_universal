<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Groq Cloud server backend.
 *
 * Groq speaks the OpenAI protocol at a fixed base URI
 * (https://api.groq.com/openai/v1). This backend only overrides the endpoint
 * and prefills routing metadata from Groq's published list prices so smart
 * routing can compare Groq vs local/paid candidates without manual entry.
 *
 * Pricing is a maintained lookup table (USD per 1M tokens), not live data —
 * verify against https://console.groq.com/docs/models when Groq changes the
 * catalog. Free-tier API keys work for discovery and light chat (rate-limited).
 */
#[AiServerBackend(
  id: 'groq',
  label: new TranslatableMarkup('Groq'),
  description: new TranslatableMarkup('GroqCloud (api.groq.com): very fast OpenAI-compatible inference (Llama, GPT-OSS, Qwen, Whisper, ...). Pricing and context length are prefilled for smart routing. Free tier available with rate limits.'),
)]
class Groq extends OpenAiCompatible {

  /**
   * Fixed OpenAI-compatible base URI for GroqCloud.
   */
  protected const DEFAULT_BASE_URI = 'https://api.groq.com/openai/v1';

  /**
   * Routing metadata by raw-model-id substring, first match wins.
   *
   * Values: cost_input / cost_output in USD per 1M tokens (on-demand list
   * prices from Groq docs), quality_tier 1-5, context_length in tokens.
   * Whisper is billed per hour of audio, not tokens — only tier is set.
   *
   * Longer / more specific patterns must appear before shorter ones.
   */
  protected const MODEL_METADATA = [
    'llama-3.1-8b-instant' => [
      'cost_input' => 0.05,
      'cost_output' => 0.08,
      'quality_tier' => 3,
      'context_length' => 131072,
    ],
    'llama-3.3-70b-versatile' => [
      'cost_input' => 0.59,
      'cost_output' => 0.79,
      'quality_tier' => 4,
      'context_length' => 131072,
    ],
    'llama-4-scout' => [
      'cost_input' => 0.11,
      'cost_output' => 0.34,
      'quality_tier' => 4,
      'context_length' => 131072,
    ],
    'gpt-oss-120b' => [
      'cost_input' => 0.15,
      'cost_output' => 0.60,
      'quality_tier' => 4,
      'context_length' => 131072,
    ],
    'gpt-oss-safeguard-20b' => [
      'cost_input' => 0.075,
      'cost_output' => 0.30,
      'quality_tier' => 3,
      'context_length' => 131072,
    ],
    'gpt-oss-20b' => [
      'cost_input' => 0.075,
      'cost_output' => 0.30,
      'quality_tier' => 3,
      'context_length' => 131072,
    ],
    'qwen3.6-27b' => [
      'cost_input' => 0.60,
      'cost_output' => 3.00,
      'quality_tier' => 4,
      'context_length' => 131072,
    ],
    'qwen3-32b' => [
      'cost_input' => 0.29,
      'cost_output' => 0.59,
      'quality_tier' => 4,
      'context_length' => 131072,
    ],
    'llama-prompt-guard' => [
      'cost_input' => 0.03,
      'cost_output' => 0.03,
      'quality_tier' => 2,
      'context_length' => 512,
    ],
    'whisper-large-v3-turbo' => [
      'quality_tier' => 3,
    ],
    'whisper-large-v3' => [
      'quality_tier' => 3,
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(AiUniversalServerInterface $server): string {
    // Groq always uses the fixed public endpoint (host field is ignored).
    return self::DEFAULT_BASE_URI;
  }

  /**
   * {@inheritdoc}
   *
   * Prompt-guard style models are moderation even when the id does not match
   * the generic llama-guard / shieldgemma heuristics.
   */
  public function detectOperationTypes(array $modelEntry): array {
    $id = strtolower($modelEntry['id'] ?? '');
    if (str_contains($id, 'prompt-guard') || str_contains($id, 'safeguard')) {
      return ['moderation'];
    }
    return parent::detectOperationTypes($modelEntry);
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

    return parent::detectModelMetadata($modelEntry);
  }

}
