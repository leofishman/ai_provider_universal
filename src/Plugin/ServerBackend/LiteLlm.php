<?php

namespace Drupal\ai_provider_universal\Plugin\ServerBackend;

use Drupal\ai_provider_universal\Attribute\ServerBackend;
use Drupal\ai_provider_universal\Entity\UniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * LiteLLM proxy server backend.
 *
 * A LiteLLM proxy speaks the OpenAI protocol for inference, so the generic
 * execution path applies untouched. What this backend adds is discovery via
 * LiteLLM's richer /model/info endpoint, which exposes a structured "mode"
 * per model (chat, embedding, image_generation, ...) plus per-token costs
 * and context window — no name heuristics, no hardcoded price table.
 *
 * If the key is not allowed to read /model/info, discovery degrades
 * gracefully to the plain /v1/models catalog with name-based detection.
 * Managed LiteLLM services (amazee.ai) get their own subclass so they
 * appear as distinct providers in the UI.
 */
#[ServerBackend(
  id: 'litellm',
  label: new TranslatableMarkup('LiteLLM'),
  description: new TranslatableMarkup("Self-hosted LiteLLM proxy servers. Operation types, pricing and context length are read from LiteLLM's /model/info endpoint. For amazee.ai use the dedicated amazee.ai backend."),
)]
class LiteLlm extends OpenAiCompatible {

  /**
   * Map from LiteLLM model_info.mode to operation type.
   */
  protected const MODE_MAP = [
    'chat'                => 'chat',
    'completion'          => 'chat',
    'embedding'           => 'embeddings',
    'image_generation'    => 'text_to_image',
    'audio_transcription' => 'speech_to_text',
    'audio_speech'        => 'text_to_speech',
    'rerank'              => 'rerank',
    'moderation'          => 'moderation',
  ];

  /**
   * {@inheritdoc}
   *
   * Fetches LiteLLM's /model/info (served at the proxy root, not under
   * /v1), which wraps each model as {model_name, litellm_params,
   * model_info}. Falls back to the standard OpenAI catalog when the key
   * lacks access to it.
   */
  public function listModels(UniversalServerInterface $server): array {
    $base = $this->getBaseUri($server);
    $root = preg_replace('~/v1/?$~', '', rtrim($base, '/'));

    try {
      $client = $this->httpClientFactory->fromOptions(['timeout' => $server->getTimeout() ?: 600]);
      $response = $client->request('GET', $root . '/model/info', [
        'headers' => ['Accept' => 'application/json'] + $this->authHeaders($server),
      ]);
      $data = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Throwable) {
      return parent::listModels($server);
    }

    $models = [];
    foreach ($data['data'] ?? [] as $entry) {
      if (empty($entry['model_name'])) {
        continue;
      }
      // LiteLLM lists one entry per deployment; first one per name wins.
      $models[$entry['model_name']] ??= ['id' => $entry['model_name']] + $entry;
    }
    return array_values($models);
  }

  /**
   * {@inheritdoc}
   */
  public function detectOperationTypes(array $modelEntry): array {
    $mode = $modelEntry['model_info']['mode'] ?? NULL;
    if ($mode && isset(self::MODE_MAP[$mode])) {
      return [self::MODE_MAP[$mode]];
    }
    return parent::detectOperationTypes($modelEntry);
  }

  /**
   * {@inheritdoc}
   *
   * LiteLLM reports costs in USD per token; the router works in USD per
   * 1M tokens.
   */
  public function detectModelMetadata(array $modelEntry): array {
    $metadata = [];
    $info = $modelEntry['model_info'] ?? [];

    if (is_numeric($info['input_cost_per_token'] ?? NULL)) {
      $metadata['cost_input'] = (float) $info['input_cost_per_token'] * 1000000;
    }
    if (is_numeric($info['output_cost_per_token'] ?? NULL)) {
      $metadata['cost_output'] = (float) $info['output_cost_per_token'] * 1000000;
    }

    $ctx = $info['max_input_tokens'] ?? $info['max_tokens'] ?? NULL;
    if (is_numeric($ctx) && $ctx > 0) {
      $metadata['context_length'] = (int) $ctx;
    }

    return $metadata;
  }

}
