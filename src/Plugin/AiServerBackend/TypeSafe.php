<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Exception\AiMissingFeatureException;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Backend\AiInferenceBackendInterface;
use Drupal\ai_provider_universal\Backend\AiServerBackendPluginBase;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * TypeSafe (Jev) server backend, speaking the native System One API.
 *
 * Jev is not a text generator: it takes a state and a set of typed questions
 * (noul = probability a statement is true, choice = one option from a set,
 * score = a level on a rubric) and returns typed answers with calibrated
 * confidence. It is exposed as chat so any AI core caller can reach it:
 *
 * - The conversation (every non-system turn) becomes the state.
 * - The questions come, per call, from the system prompt as a JSON object,
 *   or else from the model's extra request parameters (`questions:` in
 *   YAML, the model's default question set).
 * - The answer text is the JSON "answers" object, so callers decode it the
 *   same way they decode structured output from an LLM.
 *
 * @code
 * questions:
 *   is_urgent:
 *     type: noul
 *     instructions: The message conveys urgency or time-sensitivity
 * @endcode
 *
 * @see https://docs.typesafe.ai/primitives
 */
#[AiServerBackend(
  id: 'typesafe',
  label: new TranslatableMarkup('TypeSafe (Jev)'),
  description: new TranslatableMarkup('TypeSafe System One models (api.typesafe.ai): typed decisions — yes/no probability, choice, score — with calibrated confidence, not text generation. Questions are set per model in the extra request parameters (questions:) or per call as a JSON system prompt; the answer is JSON. Requires an API key.'),
)]
class TypeSafe extends AiServerBackendPluginBase implements ContainerFactoryPluginInterface, AiInferenceBackendInterface {

  /**
   * Default API endpoint, used when the server entity leaves the host empty.
   */
  protected const DEFAULT_BASE_URI = 'https://api.typesafe.ai/v1';

  /**
   * Jev list price: USD 42 per billion input tokens.
   *
   * No output price is published. Only ids starting with "jev" get it: the
   * same protocol is served by self-hosted decision models (Laya behind a
   * System One wrapper), which are free. Quality tier 1 keeps smart routing
   * from picking a decision model for anything but the simplest prompts;
   * exclude them from the candidates of routes that serve free-form chat.
   */
  protected const JEV_PRICE = ['cost_input' => 0.042];

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected ClientFactory $httpClientFactory,
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
      $container->has('key.repository') ? $container->get('key.repository') : NULL,
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(AiUniversalServerInterface $server): string {
    $host = rtrim($server->getHostName(), '/');
    if ($host === '') {
      return static::DEFAULT_BASE_URI;
    }
    if ($port = $server->getPort()) {
      $host .= ':' . $port;
    }
    return $host . '/v1';
  }

  /**
   * {@inheritdoc}
   *
   * The catalog returns model cards keyed by "name", not "id".
   */
  public function listModels(AiUniversalServerInterface $server): array {
    $client = $this->httpClientFactory->fromOptions(['timeout' => $server->getTimeout() ?: 60]);
    $response = $client->request('GET', rtrim($this->getBaseUri($server), '/') . '/models', [
      'headers' => ['Accept' => 'application/json'] + $this->authHeaders($server),
    ]);
    $data = Json::decode($response->getBody()->getContents()) ?: [];
    // ponytail: envelope undocumented (the SDK returns a bare list); accept
    // both until verified against the live API.
    $cards = array_is_list($data) ? $data : ($data['data'] ?? $data['models'] ?? []);

    $models = [];
    foreach ($cards as $card) {
      $id = $card['id'] ?? $card['name'] ?? NULL;
      if ($id) {
        $models[] = ['id' => $id] + $card;
      }
    }
    return $models;
  }

  /**
   * {@inheritdoc}
   */
  public function detectOperationTypes(array $modelEntry): array {
    // Text classification is served by the provider, which turns the labels
    // into one yes/no question each; it needs AI 1.4 or newer.
    return class_exists('Drupal\\ai\\OperationType\\TextClassification\\TextClassificationOutput')
      ? ['chat', 'text_classification']
      : ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function detectModelMetadata(array $modelEntry): array {
    $jev = str_starts_with(strtolower($modelEntry['id'] ?? ''), 'jev');
    return ['quality_tier' => 1] + ($jev ? static::JEV_PRICE : []);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $modelId, AiUniversalServerInterface $server, array $configuration = [], bool $streamed = FALSE): ChatOutput {
    if ($streamed) {
      throw new AiMissingFeatureException('TypeSafe answers are typed decisions returned in one piece; streaming is not supported.');
    }

    [$system, $state] = $this->splitInput($input);
    // Per-call questions override the model's default set. AI core delivers
    // a ChatInput system prompt twice (as the prompt and, via the provider's
    // system role, as a system message), and a caller may add plain-text
    // system turns: the first part that decodes wins.
    $questions = NULL;
    foreach ($system as $part) {
      $questions ??= is_array($decoded = Json::decode($part)) && $decoded !== [] ? $decoded : NULL;
    }
    $questions ??= $configuration['questions'] ?? NULL;
    if (!is_array($questions) || $questions === []) {
      throw new AiRequestErrorException('TypeSafe needs questions: set "questions" in the model\'s extra request parameters, or send them as a JSON object in the system prompt. See https://docs.typesafe.ai/primitives');
    }
    if (trim($state) === '') {
      throw new AiRequestErrorException('TypeSafe needs a state: the conversation has no user content.');
    }

    // Only the documented fields are sent: sampling and other generic chat
    // parameters mean nothing to a decision model.
    $data = $this->request($server, [
      'model' => $modelId,
      'state' => $state,
      'questions' => $questions,
    ]);

    $usage = $data['usage'] ?? [];
    $in = (int) ($usage['input_tokens'] ?? 0);
    $out = (int) ($usage['output_tokens'] ?? 0);

    return new ChatOutput(
      new ChatMessage('assistant', Json::encode($data['answers'] ?? [])),
      $data,
      ['model' => $data['model'] ?? NULL],
      new TokenUsageDto(input: $in, output: $out, total: $in + $out),
    );
  }

  /**
   * Splits chat input into the system parts and the state.
   *
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input in any shape AI core passes down.
   *
   * @return array{0: string[], 1: string}
   *   Tuple of [system parts, state]. A single turn is sent as-is; a longer
   *   conversation is rendered as "role: text" lines so Jev sees who said
   *   what.
   */
  protected function splitInput(array|string|ChatInput $input): array {
    if (is_string($input)) {
      return [[], $input];
    }

    $system = [];
    $turns = [];
    if ($input instanceof ChatInput) {
      $system[] = trim($input->getSystemPrompt());
      $input = $input->getMessages();
    }
    foreach ($input as $entry) {
      $role = $entry instanceof ChatMessage ? $entry->getRole() : ($entry['role'] ?? 'user');
      $text = trim($entry instanceof ChatMessage ? $entry->getText() : (string) ($entry['content'] ?? ''));
      if ($text === '') {
        continue;
      }
      if ($role === 'system' || $role === 'developer') {
        $system[] = $text;
        continue;
      }
      $turns[] = [$role, $text];
    }

    $state = count($turns) === 1
      ? $turns[0][1]
      : implode("\n", array_map(static fn ($t) => $t[0] . ': ' . $t[1], $turns));

    return [array_values(array_filter($system)), $state];
  }

  /**
   * Performs the System One request and maps failures to AI core exceptions.
   *
   * @param \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server
   *   The server.
   * @param array $payload
   *   The request body.
   *
   * @return array
   *   The decoded response.
   */
  protected function request(AiUniversalServerInterface $server, array $payload): array {
    $client = $this->httpClientFactory->fromOptions(['timeout' => $server->getTimeout() ?: 60]);
    try {
      $response = $client->request('POST', rtrim($this->getBaseUri($server), '/') . '/systemone', [
        'headers' => ['Content-Type' => 'application/json'] + $this->authHeaders($server),
        'json' => $payload,
      ]);
    }
    catch (RequestException $e) {
      throw $this->mapException($e);
    }
    catch (\Throwable $e) {
      throw new AiRequestErrorException('TypeSafe request failed: ' . $e->getMessage(), $e->getCode(), $e);
    }

    return Json::decode($response->getBody()->getContents()) ?: [];
  }

  /**
   * Maps an HTTP failure to the AI core exception callers expect.
   *
   * Errors arrive as {"detail": {"error_type": ..., "message": ...}}; request
   * validation failures (422) carry a list under "detail" instead.
   *
   * @param \GuzzleHttp\Exception\RequestException $e
   *   The Guzzle exception.
   *
   * @return \Throwable
   *   The exception to throw.
   */
  protected function mapException(RequestException $e): \Throwable {
    $response = $e->getResponse();
    $status = $response?->getStatusCode() ?? 0;
    $detail = $response ? (Json::decode((string) $response->getBody())['detail'] ?? NULL) : NULL;
    $type = is_array($detail) ? (string) ($detail['error_type'] ?? '') : '';
    $message = match (TRUE) {
      is_array($detail) && isset($detail['message']) => (string) $detail['message'],
      is_array($detail) || is_string($detail) => Json::encode($detail),
      default => $e->getMessage(),
    };

    $this->loggerFactory?->get('ai_provider_universal')->error(
      'TypeSafe request failed with status @status: @message',
      ['@status' => $status, '@message' => $message],
    );

    return match (TRUE) {
      $status === 429 => new AiRateLimitException($message),
      $status === 402 || str_contains($type, 'quota') || str_contains($type, 'billing') => new AiQuotaException($message),
      $status === 401 || $status === 403 => new AiSetupFailureException($message),
      default => new AiRequestErrorException($message, $status, $e),
    };
  }

  /**
   * Builds the Bearer authentication header, empty when no key is selected.
   *
   * @param \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server
   *   The server.
   *
   * @return array<string, string>
   *   Header map.
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

}
