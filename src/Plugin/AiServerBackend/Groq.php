<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Groq Cloud server backend.
 *
 * Groq speaks the OpenAI protocol at a fixed base URI
 * (https://api.groq.com/openai/v1). Discovery uses /v1/models, which already
 * carries rich per-model metadata: pricing (USD per token), context_length,
 * input/output modalities, and supported_features (tools, json_mode,
 * reasoning, ...). This backend reads those live fields for routing costs
 * and operation types — no hardcoded price table.
 *
 * Note on "reasoning": Groq flags models that support reasoning in
 * supported_features (persisted on the model entity), but the catalog does
 * not publish effort levels (low/medium/high). Those stay manual on the
 * model entity (reasoning_effort) when the provider supports the parameter.
 *
 * Free-tier API keys work for discovery and light chat (rate-limited).
 */
#[AiServerBackend(
  id: 'groq',
  label: new TranslatableMarkup('Groq'),
  description: new TranslatableMarkup('GroqCloud (api.groq.com): very fast OpenAI-compatible inference (Llama, GPT-OSS, Qwen, Whisper, ...). Pricing, context and supported_features (tools, reasoning, json_mode, ...) are read live from the catalog. Free tier available with rate limits.'),
)]
class Groq extends OpenAiCompatible {

  /**
   * Fixed OpenAI-compatible base URI for GroqCloud.
   */
  protected const DEFAULT_BASE_URI = 'https://api.groq.com/openai/v1';

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
   * Prefer structured catalog fields:
   * - output_modalities: transcription → speech_to_text, speech →
   *   text_to_speech
   * - id heuristics: prompt-guard / safeguard → moderation
   * - else generic name heuristics (whisper, embed, chat, ...)
   */
  public function detectOperationTypes(array $modelEntry): array {
    $outputs = $modelEntry['output_modalities'] ?? [];
    if (is_array($outputs)) {
      if (in_array('transcription', $outputs, TRUE)) {
        return ['speech_to_text'];
      }
      if (in_array('speech', $outputs, TRUE) || in_array('audio', $outputs, TRUE)) {
        return ['text_to_speech'];
      }
      if (in_array('image', $outputs, TRUE)) {
        return ['text_to_image'];
      }
    }

    $id = strtolower($modelEntry['id'] ?? '');
    if (str_contains($id, 'prompt-guard') || str_contains($id, 'safeguard')) {
      return ['moderation'];
    }

    return parent::detectOperationTypes($modelEntry);
  }

  /**
   * {@inheritdoc}
   *
   * Pricing is published as USD-per-token strings (same shape as OpenRouter);
   * the router works in USD per 1M tokens. Context prefers context_length,
   * then context_window. Quality tier is left unset so model_defaults.yml
   * can fill family/size guesses. supported_features is copied from the
   * catalog when present (tools, json_mode, structured_outputs, reasoning).
   */
  public function detectModelMetadata(array $modelEntry): array {
    $metadata = [];

    $prompt = $modelEntry['pricing']['prompt'] ?? NULL;
    if (is_numeric($prompt)) {
      // Round to avoid IEEE float noise (e.g. 5e-8 * 1e6 → 0.049999...).
      $metadata['cost_input'] = round((float) $prompt * 1000000, 6);
    }
    $completion = $modelEntry['pricing']['completion'] ?? NULL;
    if (is_numeric($completion)) {
      $metadata['cost_output'] = round((float) $completion * 1000000, 6);
    }

    $ctx = $modelEntry['context_length'] ?? $modelEntry['context_window'] ?? NULL;
    if (is_numeric($ctx) && $ctx > 0) {
      $metadata['context_length'] = (int) $ctx;
    }

    $features = $modelEntry['supported_features'] ?? NULL;
    if (is_array($features) && $features !== []) {
      $normalized = [];
      foreach ($features as $feature) {
        if (is_string($feature) && $feature !== '') {
          $normalized[] = strtolower($feature);
        }
      }
      if ($normalized !== []) {
        $metadata['supported_features'] = array_values(array_unique($normalized));
      }
    }

    return $metadata;
  }

}
