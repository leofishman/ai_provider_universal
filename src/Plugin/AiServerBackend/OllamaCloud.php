<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Ollama Cloud server backend.
 *
 * The ollama.com API speaks the OpenAI protocol; only the base URI differs
 * from the generic backend. Its /v1/models carries bare ids (no pricing or
 * context metadata), so capability detection relies on the inherited name
 * heuristics and routing metadata stays empty for the user to fill in.
 */
#[AiServerBackend(
  id: 'ollama_cloud',
  label: new TranslatableMarkup('Ollama Cloud'),
  description: new TranslatableMarkup('Ollama Cloud (ollama.com): hosted open models (DeepSeek, GLM, Gemma, ...) behind the familiar Ollama OpenAI-compatible endpoint. Requires an ollama.com API key.'),
)]
class OllamaCloud extends OpenAiCompatible {

  /**
   * Default API endpoint, used when the server entity leaves the host empty.
   */
  protected const DEFAULT_BASE_URI = 'https://ollama.com/v1';

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(AiUniversalServerInterface $server): string {
    if (!$server->getHostName()) {
      return self::DEFAULT_BASE_URI;
    }
    return parent::getBaseUri($server);
  }

}
