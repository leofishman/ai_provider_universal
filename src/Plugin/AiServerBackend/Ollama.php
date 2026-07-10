<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Local Ollama server backend.
 *
 * Ollama's OpenAI-compatible surface (/v1/chat/completions, /v1/embeddings,
 * /v1/models) already works through the generic backend, but /v1/models
 * returns bare ids with no context length or family metadata. This backend
 * keeps that execution path and enriches discovery by calling Ollama's native
 * POST /api/show per model, so smart routing gets real context windows and
 * zero local cost without manual data entry.
 *
 * Host/port are required (typical: http://127.0.0.1:11434, or
 * http://host.docker.internal:11434 from DDEV). API key is optional —
 * only needed when the Ollama server enforces authentication.
 */
#[AiServerBackend(
  id: 'ollama',
  label: new TranslatableMarkup('Ollama'),
  description: new TranslatableMarkup('Local Ollama server (default port 11434). OpenAI-compatible chat and embeddings; discovery enriches models via /api/show (context length, family, capabilities). Costs prefilled as free for smart routing.'),
)]
class Ollama extends OpenAiCompatible {

  /**
   * {@inheritdoc}
   *
   * After the OpenAI /v1/models catalog, each entry is enriched with the
   * native /api/show payload (details, model_info, capabilities, parameters)
   * so detect* methods can read structured Ollama fields. A failed show call
   * leaves that entry bare — name heuristics still apply.
   */
  public function listModels(AiUniversalServerInterface $server): array {
    $models = parent::listModels($server);
    if ($models === []) {
      return $models;
    }

    $nativeBase = $this->getNativeBaseUri($server);
    if ($nativeBase === '') {
      return $models;
    }

    // /api/show serves local metadata; never worth the inference timeout.
    $client = $this->httpClientFactory->fromOptions([
      'timeout' => 10,
    ]);
    $options = [
      'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
      ] + $this->getHttpHeaders($server) + $this->authHeaders($server),
    ];

    foreach ($models as &$entry) {
      $id = $entry['id'] ?? NULL;
      if (!is_string($id) || $id === '') {
        continue;
      }
      try {
        $response = $client->request('POST', $nativeBase . '/api/show', $options + [
          'json' => ['model' => $id],
        ]);
        $show = json_decode($response->getBody()->getContents(), TRUE);
        if (!is_array($show)) {
          continue;
        }
        // Attach Ollama-native fields under stable keys used by detect*.
        foreach (['details', 'model_info', 'capabilities', 'parameters'] as $key) {
          if (array_key_exists($key, $show)) {
            $entry[$key] = $show[$key];
          }
        }
      }
      catch (\Throwable) {
        // Discovery still succeeds with bare OpenAI catalog entries.
      }
    }
    unset($entry);

    return $models;
  }

  /**
   * {@inheritdoc}
   *
   * Prefer Ollama's structured capabilities / family when present, then fall
   * back to the generic name heuristics (nomic-embed, llama-guard, ...).
   */
  public function detectOperationTypes(array $modelEntry): array {
    $capabilities = $modelEntry['capabilities'] ?? [];
    if (is_array($capabilities)) {
      $capabilities = array_map('strtolower', array_filter($capabilities, 'is_string'));
      if (in_array('embedding', $capabilities, TRUE) || in_array('embeddings', $capabilities, TRUE)) {
        return ['embeddings'];
      }
    }

    $family = strtolower((string) ($modelEntry['details']['family'] ?? ''));
    if ($family !== '' && preg_match('/bert|nomic|embed/', $family)) {
      return ['embeddings'];
    }

    return parent::detectOperationTypes($modelEntry);
  }

  /**
   * {@inheritdoc}
   *
   * Local inference is free for routing comparisons. Context length comes
   * from model_info (*\.context_length) or a num_ctx line in the Modelfile
   * parameters block when the user set an explicit window.
   */
  public function detectModelMetadata(array $modelEntry): array {
    $metadata = parent::detectModelMetadata($modelEntry);

    // Prefer the running Modelfile num_ctx when set: that is the context
    // Ollama actually allocates, not just the architecture maximum.
    $parameters = $modelEntry['parameters'] ?? '';
    if (is_string($parameters) && preg_match('/(?:^|\n)\s*num_ctx\s+(\d+)/i', $parameters, $match)) {
      $metadata['context_length'] = (int) $match[1];
    }
    elseif (!isset($metadata['context_length'])) {
      $ctx = $this->extractContextLength($modelEntry['model_info'] ?? []);
      if ($ctx !== NULL) {
        $metadata['context_length'] = $ctx;
      }
    }

    // Local = free. Zero costs let the smart router prefer Ollama over paid
    // candidates when quality tier is enough.
    $metadata['cost_input'] = 0.0;
    $metadata['cost_output'] = 0.0;

    return $metadata;
  }

  /**
   * Ollama native API root (without the OpenAI /v1 suffix).
   */
  protected function getNativeBaseUri(AiUniversalServerInterface $server): string {
    $base = rtrim($this->getBaseUri($server), '/');
    if ($base === '') {
      return '';
    }
    return (string) preg_replace('#/v1$#', '', $base);
  }

  /**
   * Reads the first *.context_length value from an /api/show model_info map.
   *
   * @param array $modelInfo
   *   Key/value map from Ollama (e.g. llama.context_length => 131072).
   *
   * @return int|null
   *   Context length in tokens, or NULL when not published.
   */
  protected function extractContextLength(array $modelInfo): ?int {
    foreach ($modelInfo as $key => $value) {
      if (is_string($key) && str_ends_with($key, '.context_length') && is_numeric($value) && (int) $value > 0) {
        return (int) $value;
      }
    }
    return NULL;
  }

}
