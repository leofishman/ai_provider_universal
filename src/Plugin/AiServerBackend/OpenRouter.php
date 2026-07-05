<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * OpenRouter server backend.
 *
 * OpenRouter speaks the OpenAI protocol, so this extends the generic backend
 * and only overrides what differs: a fixed base URI (host/port on the server
 * entity are optional), capability detection from the architecture block of
 * /v1/models (output_modalities instead of name regexes), and routing
 * metadata (pricing and context length) read straight from the catalog
 * payload — no hardcoded table, prices stay current automatically.
 */
#[AiServerBackend(
  id: 'openrouter',
  label: new TranslatableMarkup('OpenRouter'),
  description: new TranslatableMarkup('OpenRouter unified API (openrouter.ai): 300+ models from OpenAI, Anthropic, Google, Meta and others behind one OpenAI-compatible endpoint. Pricing and context length are prefilled for smart routing.'),
)]
class OpenRouter extends OpenAiCompatible {

  /**
   * Default API endpoint, used when the server entity leaves the host empty.
   */
  protected const DEFAULT_BASE_URI = 'https://openrouter.ai/api/v1';

  /**
   * {@inheritdoc}
   *
   * OpenRouter's optional attribution headers identify the calling app in
   * its usage rankings (https://openrouter.ai/docs/app-attribution).
   */
  public function getHttpHeaders(AiUniversalServerInterface $server): array {
    return [
      'HTTP-Referer' => 'https://www.drupal.org/project/ai_provider_universal',
      'X-Title' => 'Drupal AI Provider Universal',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(AiUniversalServerInterface $server): string {
    if (!$server->getHostName()) {
      return self::DEFAULT_BASE_URI;
    }
    return parent::getBaseUri($server);
  }

  /**
   * {@inheritdoc}
   *
   * OpenRouter's /v1/models exposes architecture.output_modalities per model;
   * a model that can emit images is registered as text_to_image (Gemini
   * image generation and similar multimodal models). Everything else falls
   * back to the generic name heuristics, which classify the moderation and
   * rerank models OpenRouter carries (llama-guard, ...) correctly.
   */
  public function detectOperationTypes(array $modelEntry): array {
    $outputs = $modelEntry['architecture']['output_modalities'] ?? [];

    if (in_array('image', $outputs, TRUE)) {
      return ['text_to_image'];
    }
    return parent::detectOperationTypes($modelEntry);
  }

  /**
   * {@inheritdoc}
   *
   * Pricing comes as strings in USD per token; the router works in USD per
   * 1M tokens. Context length is a top-level field of the catalog entry.
   */
  public function detectModelMetadata(array $modelEntry): array {
    $metadata = [];

    $prompt = $modelEntry['pricing']['prompt'] ?? NULL;
    if (is_numeric($prompt)) {
      $metadata['cost_input'] = (float) $prompt * 1000000;
    }
    $completion = $modelEntry['pricing']['completion'] ?? NULL;
    if (is_numeric($completion)) {
      $metadata['cost_output'] = (float) $completion * 1000000;
    }

    $ctx = $modelEntry['context_length'] ?? NULL;
    if (is_numeric($ctx) && $ctx > 0) {
      $metadata['context_length'] = (int) $ctx;
    }

    return $metadata;
  }

}
