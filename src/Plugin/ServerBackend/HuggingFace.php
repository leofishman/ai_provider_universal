<?php

namespace Drupal\ai_provider_universal\Plugin\ServerBackend;

use Drupal\ai_provider_universal\Attribute\ServerBackend;
use Drupal\ai_provider_universal\Entity\UniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Hugging Face Inference Providers server backend.
 *
 * The HF router (router.huggingface.co) speaks the OpenAI protocol, so this
 * extends the generic backend and only overrides the base URI, capability
 * detection from the architecture block of /v1/models, and routing metadata.
 * Each catalog entry lists per-provider offers; pricing is taken from the
 * cheapest live provider and context length from the largest, matching the
 * router's ":cheapest" selection.
 */
#[ServerBackend(
  id: 'huggingface',
  label: new TranslatableMarkup('Hugging Face'),
  description: new TranslatableMarkup('Hugging Face Inference Providers (router.huggingface.co): open models served by Together, Fireworks, Novita, DeepInfra and others behind one OpenAI-compatible endpoint. Pricing and context length are prefilled for smart routing.'),
)]
class HuggingFace extends OpenAiCompatible {

  /**
   * Default API endpoint, used when the server entity leaves the host empty.
   */
  protected const DEFAULT_BASE_URI = 'https://router.huggingface.co/v1';

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(UniversalServerInterface $server): string {
    if (!$server->getHostName()) {
      return self::DEFAULT_BASE_URI;
    }
    return parent::getBaseUri($server);
  }

  /**
   * {@inheritdoc}
   *
   * The router's /v1/models exposes architecture.output_modalities per model,
   * same shape as OpenRouter; everything else falls back to the generic name
   * heuristics.
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
   * Provider pricing is already USD per 1M tokens. The cheapest live offer
   * (by input price) supplies the cost pair; context length is the maximum
   * any live provider serves.
   */
  public function detectModelMetadata(array $modelEntry): array {
    $metadata = [];
    $best = NULL;
    $maxCtx = 0;

    foreach ($modelEntry['providers'] ?? [] as $provider) {
      if (($provider['status'] ?? '') !== 'live') {
        continue;
      }
      $ctx = $provider['context_length'] ?? 0;
      if (is_numeric($ctx) && $ctx > $maxCtx) {
        $maxCtx = (int) $ctx;
      }
      $input = $provider['pricing']['input'] ?? NULL;
      if (is_numeric($input) && ($best === NULL || $input < $best['pricing']['input'])) {
        $best = $provider;
      }
    }

    if ($best) {
      $metadata['cost_input'] = (float) $best['pricing']['input'];
      if (is_numeric($best['pricing']['output'] ?? NULL)) {
        $metadata['cost_output'] = (float) $best['pricing']['output'];
      }
    }
    if ($maxCtx > 0) {
      $metadata['context_length'] = $maxCtx;
    }

    return $metadata;
  }

}
