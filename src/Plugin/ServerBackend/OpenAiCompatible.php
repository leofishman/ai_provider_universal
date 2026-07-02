<?php

namespace Drupal\ai_provider_universal\Plugin\ServerBackend;

use Drupal\ai_provider_universal\Attribute\ServerBackend;
use Drupal\ai_provider_universal\Backend\ServerBackendPluginBase;
use Drupal\ai_provider_universal\Entity\UniversalServerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * OpenAI-compatible server backend.
 *
 * Covers llama.cpp, Ollama, vLLM, LM Studio, LiteLLM, Fireworks, OpenAI and
 * any other server exposing the OpenAI REST protocol. Capability detection
 * uses llama.cpp's per-model "status.args" when present (router mode), then
 * the HuggingFace pipeline_tag of the model's --hf-repo, then model-name
 * heuristics, and finally defaults to chat.
 */
#[ServerBackend(
  id: 'openai_compatible',
  label: new TranslatableMarkup('OpenAI-compatible'),
  description: new TranslatableMarkup('llama.cpp, Ollama, vLLM, LM Studio, LiteLLM, Fireworks, OpenAI and any other server speaking the OpenAI REST protocol.'),
)]
class OpenAiCompatible extends ServerBackendPluginBase implements ContainerFactoryPluginInterface {

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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(UniversalServerInterface $server): string {
    $host = rtrim($server->getHostName(), '/');
    if ($port = $server->getPort()) {
      $host .= ':' . $port;
    }
    return $host . '/v1';
  }

  /**
   * {@inheritdoc}
   */
  public function listModels(UniversalServerInterface $server): array {
    $client = $this->createClient($server);
    $response = $client->models()->list()->toArray();
    return $response['data'] ?? [];
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
   * Creates an OpenAI client for the given server.
   */
  protected function createClient(UniversalServerInterface $server): \OpenAI\Client {
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
    catch (\Throwable) {
      $tag = NULL;
    }

    $cache[$repo] = $tag;
    $this->state->set('ai_provider_universal.hf_tag_cache', $cache);

    return $tag;
  }

}
