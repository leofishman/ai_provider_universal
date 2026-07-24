<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Backend\AiInferenceBackendInterface;
use Drupal\ai_provider_universal\Backend\AiServerBackendPluginBase;
use Drupal\ai_provider_universal\Chat\AnthropicStreamedChatMessageIterator;
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
 * Anthropic (Claude) server backend, speaking the native Messages API.
 *
 * The first backend that owns inference instead of delegating it to the
 * OpenAI protocol: it implements AiInferenceBackendInterface, so the provider
 * hands chat execution over to it while everything around the call (pre-call
 * gate, per-server usage limits, usage recording, smart routing, fact check,
 * governance) stays generic.
 *
 * Going native rather than through Anthropic's OpenAI compatibility layer
 * buys the features that layer does not expose: extended thinking, prompt
 * caching, server-side tools (web search, code execution), PDF documents and
 * the real token accounting — including cache reads, which matter for cost.
 *
 * Per-model **extra request parameters** are passed through verbatim, so
 * anything this class does not model itself is still reachable from the UI:
 * @code
 * tools:
 *   - type: web_search_20250305
 *     name: web_search
 * @endcode
 */
#[AiServerBackend(
  id: 'anthropic',
  label: new TranslatableMarkup('Anthropic (Claude)'),
  description: new TranslatableMarkup('Anthropic Claude models over the native Messages API (api.anthropic.com). Supports extended thinking, tool calling, vision, PDF documents, streaming and prompt caching. Requires an API key; host and port are not needed.'),
)]
class Anthropic extends AiServerBackendPluginBase implements ContainerFactoryPluginInterface, AiInferenceBackendInterface {

  /**
   * Default API endpoint, used when the server entity leaves the host empty.
   */
  protected const DEFAULT_BASE_URI = 'https://api.anthropic.com/v1';

  /**
   * The API version header every request must carry.
   */
  protected const API_VERSION = '2023-06-01';

  /**
   * Fallback for the required max_tokens parameter.
   *
   * Unlike OpenAI, Anthropic makes max_tokens mandatory. AI core only sends
   * one when the site configured it, so a default is needed to keep plain
   * calls working.
   */
  protected const DEFAULT_MAX_TOKENS = 4096;

  /**
   * Thinking budget in tokens per reasoning effort level.
   *
   * The API rejects budgets below 1024 and requires max_tokens to exceed the
   * budget, which ::applyReasoning() enforces.
   */
  protected const THINKING_BUDGETS = [
    'low' => 2048,
    'medium' => 8192,
    'high' => 16384,
  ];

  /**
   * OpenAI-compatible parameters the Messages API has no equivalent for.
   *
   * Dropped instead of forwarded: Anthropic rejects unknown parameters, so
   * passing them through would turn a harmless generic setting into a hard
   * request failure.
   */
  protected const UNSUPPORTED_PARAMS = [
    'frequency_penalty',
    'presence_penalty',
    'logit_bias',
    'logprobs',
    'top_logprobs',
    'n',
    'seed',
    'response_format',
    'max_completion_tokens',
    'stream_options',
    'web_search_options',
    'parallel_tool_calls',
    'server_id',
  ];

  /**
   * Routing metadata by raw-model-id substring, first match wins.
   *
   * Costs are USD per 1M tokens at list price; verify against
   * https://www.anthropic.com/pricing when a new generation ships. Context is
   * the standard window (some models offer a larger one in beta, which the
   * context length field can be raised to manually).
   */
  protected const MODEL_METADATA = [
    'claude-opus-4' => [
      'cost_input' => 15.0,
      'cost_output' => 75.0,
      'quality_tier' => 5,
      'context_length' => 200000,
      'supported_features' => ['tools', 'reasoning', 'vision'],
    ],
    'claude-sonnet-4' => [
      'cost_input' => 3.0,
      'cost_output' => 15.0,
      'quality_tier' => 5,
      'context_length' => 200000,
      'supported_features' => ['tools', 'reasoning', 'vision'],
    ],
    'claude-haiku-4' => [
      'cost_input' => 1.0,
      'cost_output' => 5.0,
      'quality_tier' => 4,
      'context_length' => 200000,
      'supported_features' => ['tools', 'reasoning', 'vision'],
    ],
    'claude-3-7-sonnet' => [
      'cost_input' => 3.0,
      'cost_output' => 15.0,
      'quality_tier' => 4,
      'context_length' => 200000,
      'supported_features' => ['tools', 'reasoning', 'vision'],
    ],
    'claude-3-5-sonnet' => [
      'cost_input' => 3.0,
      'cost_output' => 15.0,
      'quality_tier' => 4,
      'context_length' => 200000,
      'supported_features' => ['tools', 'vision'],
    ],
    'claude-3-5-haiku' => [
      'cost_input' => 0.8,
      'cost_output' => 4.0,
      'quality_tier' => 3,
      'context_length' => 200000,
      'supported_features' => ['tools', 'vision'],
    ],
    'claude-3-opus' => [
      'cost_input' => 15.0,
      'cost_output' => 75.0,
      'quality_tier' => 4,
      'context_length' => 200000,
      'supported_features' => ['tools', 'vision'],
    ],
    'claude-3-haiku' => [
      'cost_input' => 0.25,
      'cost_output' => 1.25,
      'quality_tier' => 2,
      'context_length' => 200000,
      'supported_features' => ['tools', 'vision'],
    ],
  ];

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
    // A host is only needed to point at a gateway or proxy that speaks the
    // Messages API; the public endpoint is the default.
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
   */
  public function getHttpHeaders(AiUniversalServerInterface $server): array {
    return ['anthropic-version' => static::API_VERSION];
  }

  /**
   * {@inheritdoc}
   *
   * The catalog is paginated; models are ordered newest first, so a handful
   * of pages covers every model an account can call.
   */
  public function listModels(AiUniversalServerInterface $server): array {
    $client = $this->httpClientFactory->fromOptions(['timeout' => $server->getTimeout() ?: 600]);
    $base = rtrim($this->getBaseUri($server), '/') . '/models';
    $headers = ['Accept' => 'application/json']
      + $this->getHttpHeaders($server)
      + $this->authHeaders($server);

    $models = [];
    $after = NULL;
    // Bounded so a pagination bug can never spin forever.
    for ($page = 0; $page < 10; $page++) {
      $query = ['limit' => 100] + ($after ? ['after_id' => $after] : []);
      $response = $client->request('GET', $base, ['headers' => $headers, 'query' => $query]);
      $data = Json::decode($response->getBody()->getContents()) ?: [];

      foreach ($data['data'] ?? [] as $entry) {
        $models[] = $entry;
      }
      if (empty($data['has_more']) || empty($data['last_id'])) {
        break;
      }
      $after = $data['last_id'];
    }

    return $models;
  }

  /**
   * {@inheritdoc}
   *
   * The Messages API is chat-only: Anthropic ships no embeddings, speech or
   * image generation endpoint.
   */
  public function detectOperationTypes(array $modelEntry): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function detectModelMetadata(array $modelEntry): array {
    $id = strtolower($modelEntry['id'] ?? '');
    foreach (static::MODEL_METADATA as $needle => $metadata) {
      if (str_contains($id, $needle)) {
        return $metadata;
      }
    }
    // Unknown (newer) model: the context window is the one thing every Claude
    // model has shared so far, and tier/costs fall back to model_defaults.yml.
    return ['context_length' => 200000, 'supported_features' => ['tools', 'vision']];
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $modelId, AiUniversalServerInterface $server, array $configuration = [], bool $streamed = FALSE): ChatOutput {
    $payload = $this->buildPayload($input, $modelId, $configuration);

    if ($streamed) {
      $payload['stream'] = TRUE;
      $response = $this->request($server, $payload, TRUE);
      $iterator = new AnthropicStreamedChatMessageIterator(
        AnthropicStreamedChatMessageIterator::readEvents($response->getBody()),
      );
      return new ChatOutput($iterator, $response, []);
    }

    $data = $this->request($server, $payload, FALSE);
    return $this->toChatOutput($data, $input);
  }

  /**
   * Builds the Messages API request body.
   *
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input.
   * @param string $modelId
   *   The raw model id.
   * @param array $configuration
   *   Provider configuration merged with the model's overrides.
   *
   * @return array
   *   The request payload.
   */
  protected function buildPayload(array|string|ChatInput $input, string $modelId, array $configuration): array {
    [$system, $messages] = $this->normalizeMessages($input);

    // Unknown keys are forwarded verbatim: that is what makes per-model extra
    // request parameters (thinking, cache_control, service_tier, server-side
    // tools, metadata, ...) reach the native API.
    $passthrough = array_diff_key($configuration, array_flip([
      ...static::UNSUPPORTED_PARAMS,
      'reasoning_effort',
      'max_tokens',
      'stop',
      'tools',
    ]));

    $payload = $passthrough + [
      'model' => $modelId,
      'messages' => $messages,
      'max_tokens' => (int) ($configuration['max_tokens'] ?? static::DEFAULT_MAX_TOKENS),
    ];
    if ($system !== []) {
      // Merged rather than replaced: a system block set through extra request
      // parameters (typically to attach cache_control) stays in front.
      $payload['system'] = array_merge((array) ($passthrough['system'] ?? []), $system);
    }
    // OpenAI spells stop sequences "stop".
    if (!empty($configuration['stop'])) {
      $payload['stop_sequences'] = (array) $configuration['stop'];
    }

    $tools = array_merge(
      (array) ($configuration['tools'] ?? []),
      $this->toolsFromInput($input),
    );
    if ($tools !== []) {
      $payload['tools'] = array_values($tools);
    }
    // Anthropic has no response_format: structured output is emulated with a
    // single forced tool whose schema is the requested one.
    $payload = $this->applyStructuredOutput($payload, $input);

    return $this->applyReasoning($payload, $configuration);
  }

  /**
   * Translates reasoning effort into an extended thinking block.
   *
   * @param array $payload
   *   The payload so far.
   * @param array $configuration
   *   Provider configuration for this call.
   *
   * @return array
   *   The payload with thinking applied.
   */
  protected function applyReasoning(array $payload, array $configuration): array {
    $effort = $configuration['reasoning_effort'] ?? NULL;
    // An explicit thinking block from extra request parameters wins: it can
    // express budgets this mapping cannot.
    if (isset($payload['thinking']) || $effort === NULL || $effort === 'none') {
      return $payload;
    }
    $budget = static::THINKING_BUDGETS[$effort] ?? NULL;
    if ($budget === NULL) {
      return $payload;
    }

    $payload['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
    // The budget is drawn from max_tokens, and the API requires room left
    // over for the answer itself.
    $payload['max_tokens'] = max($payload['max_tokens'], $budget + static::DEFAULT_MAX_TOKENS);
    // Thinking is incompatible with modified sampling; sending both is a
    // 400. The thinking request is the more specific intent, so it wins.
    unset($payload['temperature'], $payload['top_p'], $payload['top_k']);

    return $payload;
  }

  /**
   * Emulates structured output with a forced single-tool call.
   *
   * @param array $payload
   *   The payload so far.
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input.
   *
   * @return array
   *   The payload with the schema tool applied.
   */
  protected function applyStructuredOutput(array $payload, array|string|ChatInput $input): array {
    if (!$input instanceof ChatInput) {
      return $payload;
    }
    $schema = $input->getChatStructuredJsonSchema();
    if (empty($schema['schema'])) {
      return $payload;
    }

    $name = $schema['name'] ?? 'json_schema';
    $payload['tools'][] = array_filter([
      'name' => $name,
      'description' => $schema['description'] ?? 'Return the answer using this schema.',
      'input_schema' => $schema['schema'],
    ]);
    $payload['tool_choice'] = ['type' => 'tool', 'name' => $name];

    return $payload;
  }

  /**
   * Splits chat input into an Anthropic system block list and messages.
   *
   * Anthropic keeps the system prompt outside the message list and only
   * accepts user/assistant turns, so system messages are hoisted and tool
   * results become user messages carrying a tool_result block.
   *
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input in any shape AI core passes down.
   *
   * @return array
   *   Tuple of [system blocks, messages].
   */
  protected function normalizeMessages(array|string|ChatInput $input): array {
    $system = [];
    $messages = [];

    if (is_string($input)) {
      return [[], [['role' => 'user', 'content' => [['type' => 'text', 'text' => $input]]]]];
    }

    if ($input instanceof ChatInput) {
      if ($prompt = trim($input->getSystemPrompt())) {
        $system[] = ['type' => 'text', 'text' => $prompt];
      }
      $entries = $input->getMessages();
    }
    else {
      $entries = $input;
    }

    foreach ($entries as $entry) {
      $role = $entry instanceof ChatMessage ? $entry->getRole() : ($entry['role'] ?? 'user');
      if ($role === 'system' || $role === 'developer') {
        $text = $entry instanceof ChatMessage ? $entry->getText() : (string) ($entry['content'] ?? '');
        if (trim($text) !== '') {
          $system[] = ['type' => 'text', 'text' => $text];
        }
        continue;
      }

      $content = $entry instanceof ChatMessage
        ? $this->contentBlocks($entry)
        : [['type' => 'text', 'text' => (string) ($entry['content'] ?? '')]];
      if ($content === []) {
        continue;
      }
      // Tool results are user turns in Anthropic's model.
      $role = ($role === 'tool' || $role === 'function') ? 'user' : $role;
      $role = $role === 'assistant' ? 'assistant' : 'user';

      // Consecutive turns of the same role are merged: the API expects one
      // message per turn, and multi-tool answers arrive as separate results.
      $last = array_key_last($messages);
      if ($last !== NULL && $messages[$last]['role'] === $role) {
        $messages[$last]['content'] = array_merge($messages[$last]['content'], $content);
        continue;
      }
      $messages[] = ['role' => $role, 'content' => $content];
    }

    return [$system, $messages];
  }

  /**
   * Renders one ChatMessage as Anthropic content blocks.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage $message
   *   The message.
   *
   * @return array
   *   Content blocks: tool_result, tool_use, text, image and document.
   */
  protected function contentBlocks(ChatMessage $message): array {
    $blocks = [];
    $text = $message->getText();

    // A message answering a tool call carries its id.
    if ($toolId = $message->getToolsId()) {
      $blocks[] = array_filter([
        'type' => 'tool_result',
        'tool_use_id' => $toolId,
        'content' => $text,
      ]);
      return $blocks;
    }

    // An assistant message that called tools replays them as tool_use.
    foreach ($message->getTools() ?? [] as $tool) {
      $arguments = [];
      foreach ($tool->getArguments() as $argument) {
        $arguments[$argument->getName()] = $argument->getValue();
      }
      $blocks[] = [
        'type' => 'tool_use',
        'id' => $tool->getToolId(),
        'name' => $tool->getName(),
        'input' => (object) $arguments,
      ];
    }

    if (trim($text) !== '') {
      $blocks[] = ['type' => 'text', 'text' => $text];
    }

    foreach ($message->getImages() as $image) {
      $blocks[] = [
        'type' => 'image',
        'source' => [
          'type' => 'base64',
          'media_type' => $image->getMimeType(),
          'data' => $image->getAsBase64EncodedString(''),
        ],
      ];
    }
    // PDFs are first-class input for Claude; other file types are skipped
    // rather than sent as something the API would reject.
    foreach ($message->getFiles() as $file) {
      if ($file->getMimeType() !== 'application/pdf') {
        continue;
      }
      $blocks[] = [
        'type' => 'document',
        'source' => [
          'type' => 'base64',
          'media_type' => 'application/pdf',
          'data' => $file->getAsBase64EncodedString(''),
        ],
      ];
    }

    return $blocks;
  }

  /**
   * Converts AI core function definitions to Anthropic tool definitions.
   *
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input.
   *
   * @return array
   *   Anthropic tool definitions.
   */
  protected function toolsFromInput(array|string|ChatInput $input): array {
    if (!$input instanceof ChatInput || !$input->getChatTools()) {
      return [];
    }

    $tools = [];
    foreach ($input->getChatTools()->renderToolsArray() as $tool) {
      $function = $tool['function'] ?? [];
      if (empty($function['name'])) {
        continue;
      }
      $tools[] = array_filter([
        'name' => $function['name'],
        'description' => $function['description'] ?? '',
        // OpenAI calls the JSON schema "parameters"; an empty schema still
        // has to be an object, which is what the API validates against.
        'input_schema' => $function['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
      ]);
    }

    return $tools;
  }

  /**
   * Converts a Messages API response into a ChatOutput.
   *
   * @param array $data
   *   The decoded response.
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The originating input, used to resolve tool definitions.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The normalized output.
   */
  protected function toChatOutput(array $data, array|string|ChatInput $input): ChatOutput {
    $text = '';
    $tools = [];
    $definitions = ($input instanceof ChatInput) ? $input->getChatTools() : NULL;
    $schemaName = ($input instanceof ChatInput) ? ($input->getChatStructuredJsonSchema()['name'] ?? NULL) : NULL;

    foreach ($data['content'] ?? [] as $block) {
      if (($block['type'] ?? '') === 'text') {
        $text .= $block['text'] ?? '';
        continue;
      }
      if (($block['type'] ?? '') !== 'tool_use') {
        // Thinking and server-tool blocks stay available in the raw output.
        continue;
      }
      // The structured-output tool is an implementation detail: its arguments
      // are the answer, so they are returned as the message text.
      if ($schemaName !== NULL && ($block['name'] ?? '') === $schemaName) {
        $text .= Json::encode($block['input'] ?? []);
        continue;
      }
      $tools[] = new ToolsFunctionOutput(
        $definitions?->getFunctionByName($block['name'] ?? ''),
        $block['id'] ?? '',
        (array) ($block['input'] ?? []),
      );
    }

    $message = new ChatMessage('assistant', $text);
    if ($tools !== []) {
      $message->setTools($tools);
    }

    $usage = $data['usage'] ?? [];
    $input_tokens = (int) ($usage['input_tokens'] ?? 0);
    $cached = (int) ($usage['cache_read_input_tokens'] ?? 0);
    $output_tokens = (int) ($usage['output_tokens'] ?? 0);

    return new ChatOutput(
      $message,
      $data,
      [
        'id' => $data['id'] ?? NULL,
        'model' => $data['model'] ?? NULL,
        'stop_reason' => $data['stop_reason'] ?? NULL,
      ],
      new TokenUsageDto(
        input: $input_tokens,
        output: $output_tokens,
        total: $input_tokens + $output_tokens,
        cached: $cached ?: NULL,
      ),
    );
  }

  /**
   * Performs the HTTP request and maps failures to AI core exceptions.
   *
   * @param \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server
   *   The server.
   * @param array $payload
   *   The request body.
   * @param bool $streamed
   *   Whether to keep the body as a stream.
   *
   * @return mixed
   *   The decoded response, or the PSR-7 response when streaming.
   */
  protected function request(AiUniversalServerInterface $server, array $payload, bool $streamed): mixed {
    $client = $this->httpClientFactory->fromOptions(['timeout' => $server->getTimeout() ?: 600]);
    $options = [
      'headers' => ['Content-Type' => 'application/json']
      + $this->getHttpHeaders($server)
      + $this->authHeaders($server),
      'json' => $payload,
      'stream' => $streamed,
    ];

    try {
      $response = $client->request('POST', rtrim($this->getBaseUri($server), '/') . '/messages', $options);
    }
    catch (RequestException $e) {
      throw $this->mapException($e);
    }
    catch (\Throwable $e) {
      throw new AiRequestErrorException('Anthropic request failed: ' . $e->getMessage(), $e->getCode(), $e);
    }

    return $streamed ? $response : (Json::decode($response->getBody()->getContents()) ?: []);
  }

  /**
   * Maps an HTTP failure to the AI core exception callers expect.
   *
   * The distinction matters downstream: a quota exception is what smart
   * routing treats as "try the next candidate", while a setup failure is a
   * configuration problem no retry can fix.
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
    $body = $response ? Json::decode((string) $response->getBody()) : NULL;
    $message = $body['error']['message'] ?? $e->getMessage();

    $this->loggerFactory?->get('ai_provider_universal')->error(
      'Anthropic request failed with status @status: @message',
      ['@status' => $status, '@message' => $message],
    );

    return match (TRUE) {
      $status === 429 => new AiRateLimitException($message),
      // Anthropic answers 400 with this type when credits run out.
      ($body['error']['type'] ?? '') === 'billing_error' => new AiQuotaException($message),
      $status === 401 || $status === 403 => new AiSetupFailureException($message),
      default => new AiRequestErrorException($message, $status, $e),
    };
  }

  /**
   * Builds the authentication header, empty when no key is selected.
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
        // Anthropic authenticates with x-api-key, not a Bearer token.
        return ['x-api-key' => $keyValue];
      }
    }
    return [];
  }

}
