<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use OpenAI\Client;
use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Backend\AiServerBackendPluginBase;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * OpenAI-compatible server backend.
 *
 * Covers llama.cpp, vLLM, LM Studio, OpenAI and any other server exposing
 * the OpenAI REST protocol. Capability detection uses llama.cpp's per-model
 * "status.args" when present (router mode), then the HuggingFace pipeline_tag
 * of the model's --hf-repo, then model-name heuristics, and finally defaults
 * to chat. Prefer the dedicated Ollama backend for local Ollama instances.
 */
#[AiServerBackend(
  id: 'openai_compatible',
  label: new TranslatableMarkup('OpenAI-compatible'),
  description: new TranslatableMarkup('llama.cpp, vLLM, LM Studio, OpenAI and any other server speaking the OpenAI REST protocol. For local Ollama, prefer the dedicated Ollama backend.'),
)]
class OpenAiCompatible extends AiServerBackendPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Map from HuggingFace pipeline_tag to operation type.
   */
  protected const HF_TAG_MAP = [
    'text-generation'               => 'chat',
    'text2text-generation'          => 'chat',
    'feature-extraction'            => 'embeddings',
    'sentence-similarity'           => 'embeddings',
    'automatic-speech-recognition'  => 'speech_to_text',
    'text-to-speech'                => 'text_to_speech',
    'text-to-image'                 => 'text_to_image',
    'text-ranking'                  => 'rerank',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected ClientFactory $httpClientFactory,
    protected StateInterface $state,
    protected ?KeyRepositoryInterface $keyRepository = NULL,
    protected ?LoggerChannelFactoryInterface $loggerFactory = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client_factory'),
      $container->get('state'),
      $container->has('key.repository') ? $container->get('key.repository') : NULL,
      $container->get('logger.factory'),
    );
  }

  /**
   * Logs a discovery-time message, silently skipped in unit tests.
   *
   * @param string $level
   *   PSR-3 level (notice, warning, ...).
   * @param string $message
   *   Message with placeholders.
   * @param array $context
   *   Placeholder values.
   */
  protected function log(string $level, string $message, array $context = []): void {
    $this->loggerFactory?->get('ai_provider_universal')->log($level, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(AiUniversalServerInterface $server): string {
    $host = rtrim($server->getHostName(), '/');
    if ($host === '') {
      // No default endpoint for the generic backend; the server is unusable
      // until a host is configured. Specific backends may override this.
      return '';
    }
    if ($port = $server->getPort()) {
      $host .= ':' . $port;
    }
    return $host . '/v1';
  }

  /**
   * {@inheritdoc}
   *
   * Fetches /models with a plain HTTP request instead of the openai-php
   * client: its Model DTO drops non-standard fields (llama.cpp's status.args,
   * vLLM's max_model_len, ...) that capability and metadata detection need.
   */
  public function listModels(AiUniversalServerInterface $server): array {
    $options = ['headers' => ['Accept' => 'application/json'] + $this->getHttpHeaders($server) + $this->authHeaders($server)];

    $client = $this->httpClientFactory->fromOptions(['timeout' => $server->getTimeout() ?: 600]);
    $response = $client->request('GET', rtrim($this->getBaseUri($server), '/') . '/models', $options);
    $data = json_decode($response->getBody()->getContents(), TRUE);

    return $data['data'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function detectOperationTypes(array $modelEntry): array {
    $args = $modelEntry['status']['args'] ?? [];

    if (in_array('--embeddings', $args, TRUE)) {
      return ['embeddings'];
    }
    if (in_array('--reranking', $args, TRUE)) {
      return ['rerank'];
    }

    $hfRepo = $this->extractHfRepo($args);
    if ($hfRepo) {
      $tag = $this->getHfPipelineTag($hfRepo);
      if ($tag && isset(self::HF_TAG_MAP[$tag])) {
        return [self::HF_TAG_MAP[$tag]];
      }
    }

    $name = strtolower($modelEntry['id']);
    if (preg_match('/stable.diffusion|stable[-_]diffusion|sdxl|flux|dall[-_]e|sd[-_]cascade|flux[-_]dev/', $name)) {
      return ['text_to_image'];
    }
    if (preg_match('/whisper|wav2vec|vosk/', $name)) {
      return ['speech_to_text'];
    }
    if (preg_match('/rerank/', $name)) {
      return ['rerank'];
    }
    if (preg_match('/llama.guard|llamaguard|shield.?gemma/', $name)) {
      return ['moderation'];
    }
    if (preg_match('/embed|bge[-_]|nomic|e5[-_]|gte[-_]|minilm/', $name)) {
      return ['embeddings'];
    }

    return ['chat'];
  }

  /**
   * {@inheritdoc}
   *
   * Context length sources, in order of precedence:
   * - "--ctx-size" in status.args (llama.cpp router mode: the configured
   *   context per model preset),
   * - meta.n_ctx_train (llama.cpp single-model mode: training context),
   * - max_model_len (vLLM).
   * Ollama exposes nothing usable in /v1/models; fields stay unset there.
   */
  public function detectModelMetadata(array $modelEntry): array {
    $metadata = [];

    $args = $modelEntry['status']['args'] ?? [];
    $index = array_search('--ctx-size', $args, TRUE);
    $ctx = ($index !== FALSE && isset($args[$index + 1])) ? $args[$index + 1] : NULL;

    $ctx ??= $modelEntry['meta']['n_ctx_train'] ?? $modelEntry['max_model_len'] ?? NULL;

    if (is_numeric($ctx) && $ctx > 0) {
      $metadata['context_length'] = (int) $ctx;
    }
    return $metadata;
  }

  /**
   * Builds the Authorization header for a server, empty when unauthenticated.
   *
   * @return array<string, string>
   *   Header map with the Bearer token, or empty array.
   */
  protected function authHeaders(AiUniversalServerInterface $server): array {
    $keyId = $server->getApiKey();
    if ($keyId && $this->keyRepository) {
      $keyValue = $this->keyRepository->getKey($keyId)?->getKeyValue();
      if ($keyValue) {
        return ['Authorization' => 'Bearer ' . $keyValue];
      }
    }
    return [];
  }

  /**
   * Creates an OpenAI client for the given server.
   */
  protected function createClient(AiUniversalServerInterface $server): Client {
    $factory = \OpenAI::factory();

    $keyId = $server->getApiKey();
    if ($keyId && $this->keyRepository) {
      $keyValue = $this->keyRepository->getKey($keyId)?->getKeyValue();
      if ($keyValue) {
        $factory = $factory->withApiKey($keyValue);
      }
    }
    // If no key or no repository, proceed without (local servers often
    // don't require authentication).
    return $factory->withHttpClient(
      $this->httpClientFactory->fromOptions(['timeout' => $server->getTimeout() ?: 600])
    )->withBaseUri($this->getBaseUri($server))->make();
  }

  /**
   * Extracts the HuggingFace repo id from a server's --hf-repo argument.
   */
  protected function extractHfRepo(array $args): ?string {
    $index = array_search('--hf-repo', $args, TRUE);
    if ($index === FALSE || !isset($args[$index + 1])) {
      return NULL;
    }
    return explode(':', $args[$index + 1])[0];
  }

  /**
   * Looks up (and caches) a HuggingFace repo's pipeline_tag.
   */
  protected function getHfPipelineTag(string $repo): ?string {
    $cache = $this->state->get('ai_provider_universal.hf_tag_cache', []);
    if (array_key_exists($repo, $cache)) {
      return $cache[$repo];
    }

    try {
      $client = $this->httpClientFactory->fromOptions(['timeout' => 5]);
      $response = $client->request('GET', "https://huggingface.co/api/models/{$repo}");
      $data = json_decode($response->getBody()->getContents(), TRUE);
      $tag = $data['pipeline_tag'] ?? NULL;
    }
    catch (\Throwable $e) {
      $this->log('notice', 'Hugging Face pipeline tag lookup failed for @repo (@message); falling back to name heuristics.', [
        '@repo' => $repo,
        '@message' => $e->getMessage(),
      ]);
      $tag = NULL;
    }

    $cache[$repo] = $tag;
    $this->state->set('ai_provider_universal.hf_tag_cache', $cache);

    return $tag;
  }

}
