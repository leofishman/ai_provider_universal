<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Plugin;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use Drupal\ai_provider_universal\Backend\AiInferenceBackendInterface;
use Drupal\ai_provider_universal\Backend\AiServerBackendManager;
use Drupal\ai_provider_universal\Chat\AnthropicStreamedChatMessageIterator;
use Drupal\ai_provider_universal\Plugin\AiServerBackend\Anthropic;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_provider_universal\Kernel\Traits\HttpClientMockTrait;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the native Anthropic backend and its request/response mapping.
 *
 * @group ai_provider_universal
 */
#[CoversClass(Anthropic::class)]
#[Group('ai_provider_universal')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
final class AnthropicBackendTest extends KernelTestBase {

  use HttpClientMockTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'ai_provider_universal',
  ];

  /**
   * Captured requests, one per mocked response consumed.
   *
   * @var array<int, \Psr\Http\Message\RequestInterface>
   */
  protected array $requests = [];

  /**
   * Creates the server entity every test calls against.
   */
  protected function anthropicServer(): object {
    $server = $this->container->get('entity_type.manager')
      ->getStorage('ai_universal_server')
      ->create([
        'id' => 'claude',
        'label' => 'Anthropic',
        'backend' => 'anthropic',
        'host_name' => '',
        'port' => '',
        'api_key' => '',
        'timeout' => 600,
        'operation_types' => [],
        'model_filter' => '',
      ]);
    $server->save();
    return $server;
  }

  /**
   * Returns the backend plugin instance.
   */
  protected function backend(): Anthropic {
    /** @var \Drupal\ai_provider_universal\Plugin\AiServerBackend\Anthropic $backend */
    $backend = $this->container->get(AiServerBackendManager::class)->createInstance('anthropic');
    return $backend;
  }

  /**
   * Decodes the JSON body of the request the backend just sent.
   */
  protected function lastPayload(): array {
    $request = end($this->requests);
    $this->assertNotFalse($request, 'A request was sent.');
    return json_decode((string) $request->getBody(), TRUE) ?? [];
  }

  /**
   * Queues responses and records the requests that consume them.
   *
   * @param array<int, \Psr\Http\Message\ResponseInterface|\Exception> $responses
   *   Responses in order.
   */
  protected function mockRequests(array $responses): void {
    $this->mockHttpClientResponses($responses);
    // The trait's mock handler does not expose history, so wrap the client
    // it installed with a middleware that records requests.
    $factory = $this->container->get('http_client_factory');
    $client = $factory->fromOptions([]);
    $stack = $client->getConfig('handler');
    $stack->push(function (callable $handler) {
      return function ($request, array $options) use ($handler) {
        $this->requests[] = $request;
        return $handler($request, $options);
      };
    });
  }

  /**
   * Builds a Messages API response body.
   */
  protected function messageResponse(array $content, array $usage = []): Response {
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
      'id' => 'msg_01',
      'type' => 'message',
      'role' => 'assistant',
      'model' => 'claude-sonnet-4-5-20250929',
      'content' => $content,
      'stop_reason' => 'end_turn',
      'usage' => $usage + ['input_tokens' => 11, 'output_tokens' => 7],
    ]));
  }

  /**
   * Tests that the backend is discoverable and declares native inference.
   */
  public function testBackendIsRegisteredAsInferenceCapable(): void {
    $manager = $this->container->get(AiServerBackendManager::class);
    $this->assertArrayHasKey('anthropic', $manager->getDefinitions());

    $backend = $this->backend();
    $this->assertInstanceOf(AiInferenceBackendInterface::class, $backend);
    $this->assertSame('https://api.anthropic.com/v1', $backend->getBaseUri($this->anthropicServer()));
    $this->assertSame(['chat'], $backend->detectOperationTypes(['id' => 'claude-sonnet-4-5-20250929']));
  }

  /**
   * Tests catalog listing, including pagination.
   */
  public function testListModelsFollowsPagination(): void {
    $this->mockRequests([
      new Response(200, [], (string) json_encode([
        'data' => [['id' => 'claude-opus-4-5-20251101', 'display_name' => 'Claude Opus 4.5']],
        'has_more' => TRUE,
        'last_id' => 'claude-opus-4-5-20251101',
      ])),
      new Response(200, [], (string) json_encode([
        'data' => [['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5']],
        'has_more' => FALSE,
      ])),
    ]);

    $models = $this->backend()->listModels($this->anthropicServer());
    $this->assertSame(
      ['claude-opus-4-5-20251101', 'claude-haiku-4-5-20251001'],
      array_column($models, 'id'),
    );
    $this->assertStringContainsString('after_id=claude-opus-4-5', (string) $this->requests[1]->getUri());
    $this->assertSame('2023-06-01', $this->requests[0]->getHeaderLine('anthropic-version'));
  }

  /**
   * Tests routing metadata detection, including the unknown-model fallback.
   */
  public function testDetectModelMetadata(): void {
    $backend = $this->backend();

    $opus = $backend->detectModelMetadata(['id' => 'claude-opus-4-5-20251101']);
    $this->assertSame(15.0, $opus['cost_input']);
    $this->assertSame(5, $opus['quality_tier']);
    $this->assertContains('reasoning', $opus['supported_features']);

    $haiku3 = $backend->detectModelMetadata(['id' => 'claude-3-haiku-20240307']);
    $this->assertSame(0.25, $haiku3['cost_input']);
    $this->assertSame(2, $haiku3['quality_tier']);

    // Unknown future model: no invented pricing, but a usable context window.
    $future = $backend->detectModelMetadata(['id' => 'claude-something-9']);
    $this->assertArrayNotHasKey('cost_input', $future);
    $this->assertSame(200000, $future['context_length']);
  }

  /**
   * Tests the system prompt, roles and token usage mapping.
   */
  public function testChatMapsSystemPromptRolesAndUsage(): void {
    $this->mockRequests([
      $this->messageResponse([['type' => 'text', 'text' => 'Hola!']]),
    ]);

    $input = new ChatInput([
      new ChatMessage('system', 'Be terse.'),
      new ChatMessage('user', 'Hi'),
      new ChatMessage('assistant', 'Hello'),
      new ChatMessage('user', 'Again'),
    ]);
    $input->setSystemPrompt('You are a bot.');

    $output = $this->backend()->chat($input, 'claude-sonnet-4-5', $this->anthropicServer());

    $payload = $this->lastPayload();
    // Both system sources are hoisted out of the message list.
    $this->assertSame(
      ['You are a bot.', 'Be terse.'],
      array_column($payload['system'], 'text'),
    );
    $this->assertSame(['user', 'assistant', 'user'], array_column($payload['messages'], 'role'));
    $this->assertSame('claude-sonnet-4-5', $payload['model']);
    // Anthropic requires max_tokens; the backend supplies a default.
    $this->assertSame(4096, $payload['max_tokens']);

    $this->assertSame('Hola!', $output->getNormalized()->getText());
    $usage = $output->getTokenUsage();
    $this->assertSame(11, $usage->input);
    $this->assertSame(7, $usage->output);
    $this->assertSame(18, $usage->total);
  }

  /**
   * Tests that consecutive same-role turns are merged into one message.
   */
  public function testConsecutiveSameRoleMessagesAreMerged(): void {
    $this->mockRequests([$this->messageResponse([['type' => 'text', 'text' => 'ok']])]);

    $this->backend()->chat(
      new ChatInput([
        new ChatMessage('user', 'first'),
        new ChatMessage('user', 'second'),
      ]),
      'claude-sonnet-4-5',
      $this->anthropicServer(),
    );

    $messages = $this->lastPayload()['messages'];
    $this->assertCount(1, $messages);
    $this->assertSame(['first', 'second'], array_column($messages[0]['content'], 'text'));
  }

  /**
   * Tests tool definitions in and tool calls out.
   */
  public function testToolCallsRoundTrip(): void {
    $this->mockRequests([
      $this->messageResponse([
        ['type' => 'text', 'text' => 'Looking up.'],
        [
          'type' => 'tool_use',
          'id' => 'toolu_1',
          'name' => 'get_weather',
          'input' => ['city' => 'Rosario'],
        ],
      ]),
    ]);

    $city = new ToolsPropertyInput('city');
    $city->setType('string');
    $city->setRequired(TRUE);
    $function = new ToolsFunctionInput('get_weather');
    $function->setDescription('Current weather.');
    $function->setProperties(['city' => $city]);

    $input = new ChatInput([new ChatMessage('user', 'Weather in Rosario?')]);
    $input->setChatTools(new ToolsInput([$function]));

    $output = $this->backend()->chat($input, 'claude-sonnet-4-5', $this->anthropicServer());

    // Request: OpenAI's function/parameters shape becomes name/input_schema.
    $tool = $this->lastPayload()['tools'][0];
    $this->assertSame('get_weather', $tool['name']);
    $this->assertSame('Current weather.', $tool['description']);
    $this->assertArrayHasKey('properties', $tool['input_schema']);

    // Response: tool_use blocks become AI core tool outputs.
    $tools = $output->getNormalized()->getTools();
    $this->assertCount(1, $tools);
    $this->assertSame('get_weather', $tools[0]->getName());
    $this->assertSame('toolu_1', $tools[0]->getToolId());
    $this->assertSame('Rosario', $tools[0]->getArguments()[0]->getValue());
    $this->assertSame('Looking up.', $output->getNormalized()->getText());
  }

  /**
   * Tests a full tool round trip replayed as Anthropic content blocks.
   *
   * The assistant's call comes back as a tool_use block on the assistant
   * turn, and its result as a tool_result block on a user turn — Anthropic
   * has no "tool" role.
   */
  public function testToolResultIsSentAsUserBlock(): void {
    $this->mockRequests([$this->messageResponse([['type' => 'text', 'text' => 'Sunny.']])]);

    $call = new ChatMessage('assistant', '');
    $call->setTools([new ToolsFunctionOutput(
      (new ToolsFunctionInput('get_weather')),
      'toolu_1',
      ['city' => 'Rosario'],
      ),
    ]);
    $result = new ChatMessage('tool', '{"temp":30}');
    $result->setToolsId('toolu_1');

    $this->backend()->chat(
      new ChatInput([new ChatMessage('user', 'Weather?'), $call, $result]),
      'claude-sonnet-4-5',
      $this->anthropicServer(),
    );

    $messages = $this->lastPayload()['messages'];
    $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));

    $this->assertSame('tool_use', $messages[1]['content'][0]['type']);
    $this->assertSame('toolu_1', $messages[1]['content'][0]['id']);
    $this->assertSame(['city' => 'Rosario'], $messages[1]['content'][0]['input']);

    $this->assertSame('tool_result', $messages[2]['content'][0]['type']);
    $this->assertSame('toolu_1', $messages[2]['content'][0]['tool_use_id']);
  }

  /**
   * Tests reasoning effort translating into an extended thinking block.
   */
  public function testReasoningEffortBecomesThinking(): void {
    $this->mockRequests([$this->messageResponse([['type' => 'text', 'text' => 'ok']])]);

    $this->backend()->chat(
      new ChatInput([new ChatMessage('user', 'Prove it.')]),
      'claude-sonnet-4-5',
      $this->anthropicServer(),
      ['reasoning_effort' => 'high', 'temperature' => 0.2, 'max_tokens' => 1000],
    );

    $payload = $this->lastPayload();
    $this->assertSame(['type' => 'enabled', 'budget_tokens' => 16384], $payload['thinking']);
    // max_tokens has to leave room for the answer on top of the budget.
    $this->assertGreaterThan(16384, $payload['max_tokens']);
    // Sampling is incompatible with thinking and must not be sent.
    $this->assertArrayNotHasKey('temperature', $payload);
  }

  /**
   * Tests that thinking is dropped when a tool loop is in progress.
   *
   * Extended thinking requires the previous assistant turn to replay its
   * signed thinking blocks, which ChatMessage cannot carry.
   */
  public function testThinkingIsDroppedInsideToolLoop(): void {
    $this->mockRequests([$this->messageResponse([['type' => 'text', 'text' => 'Sunny.']])]);

    $call = new ChatMessage('assistant', '');
    $call->setTools([new ToolsFunctionOutput(new ToolsFunctionInput('get_weather'), 'toolu_1', [])]);
    $result = new ChatMessage('tool', '{"temp":30}');
    $result->setToolsId('toolu_1');

    $this->backend()->chat(
      new ChatInput([new ChatMessage('user', 'Weather?'), $call, $result]),
      'claude-sonnet-4-5',
      $this->anthropicServer(),
      ['reasoning_effort' => 'high'],
    );

    $this->assertArrayNotHasKey('thinking', $this->lastPayload());
  }

  /**
   * Tests that thinking is dropped when a tool choice is forced.
   *
   * The API only accepts tool_choice auto or none alongside thinking.
   */
  public function testThinkingIsDroppedWithForcedToolChoice(): void {
    $this->mockRequests([$this->messageResponse([['type' => 'text', 'text' => '{}']])]);

    $input = new ChatInput([new ChatMessage('user', 'Give me JSON.')]);
    $input->setChatStructuredJsonSchema([
      'name' => 'answer',
      'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
    ]);

    $this->backend()->chat($input, 'claude-sonnet-4-5', $this->anthropicServer(), ['reasoning_effort' => 'high']);

    $payload = $this->lastPayload();
    $this->assertSame('tool', $payload['tool_choice']['type']);
    $this->assertArrayNotHasKey('thinking', $payload);
  }

  /**
   * Tests that reasoning effort "none" sends no thinking block.
   */
  public function testReasoningNoneSendsNoThinking(): void {
    $this->mockRequests([$this->messageResponse([['type' => 'text', 'text' => 'ok']])]);

    $this->backend()->chat(
      new ChatInput([new ChatMessage('user', 'Hi')]),
      'claude-sonnet-4-5',
      $this->anthropicServer(),
      ['reasoning_effort' => 'none', 'temperature' => 0.2],
    );

    $payload = $this->lastPayload();
    $this->assertArrayNotHasKey('thinking', $payload);
    $this->assertSame(0.2, $payload['temperature']);
  }

  /**
   * Tests extra request parameters reaching the native API untouched.
   *
   * This is the per-model "Extra request parameters (YAML)" field: unknown
   * keys pass through, while OpenAI-only parameters Anthropic would reject
   * are dropped.
   */
  public function testExtraParamsPassThroughAndUnsupportedAreDropped(): void {
    $this->mockRequests([$this->messageResponse([['type' => 'text', 'text' => 'ok']])]);

    $this->backend()->chat(
      new ChatInput([new ChatMessage('user', 'Search the web')]),
      'claude-sonnet-4-5',
      $this->anthropicServer(),
      [
        'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search']],
        'service_tier' => 'standard_only',
        'temperature' => 0.5,
        'frequency_penalty' => 0.7,
        'stop' => ['STOP'],
      ],
    );

    $payload = $this->lastPayload();
    $this->assertSame('web_search_20250305', $payload['tools'][0]['type']);
    $this->assertSame('standard_only', $payload['service_tier']);
    $this->assertSame(0.5, $payload['temperature']);
    // OpenAI-only parameter: forwarding it would make Anthropic 400.
    $this->assertArrayNotHasKey('frequency_penalty', $payload);
    // OpenAI's "stop" is Anthropic's "stop_sequences".
    $this->assertSame(['STOP'], $payload['stop_sequences']);
    $this->assertArrayNotHasKey('stop', $payload);
  }

  /**
   * Tests structured output emulated through a forced tool call.
   */
  public function testStructuredOutputUsesForcedTool(): void {
    $this->mockRequests([
      $this->messageResponse([
        [
          'type' => 'tool_use',
          'id' => 'toolu_2',
          'name' => 'answer',
          'input' => ['sentiment' => 'positive'],
        ],
      ]),
    ]);

    $input = new ChatInput([new ChatMessage('user', 'Classify: great!')]);
    $input->setChatStructuredJsonSchema([
      'name' => 'answer',
      'schema' => ['type' => 'object', 'properties' => ['sentiment' => ['type' => 'string']]],
    ]);

    $output = $this->backend()->chat($input, 'claude-sonnet-4-5', $this->anthropicServer());

    $payload = $this->lastPayload();
    $this->assertSame(['type' => 'tool', 'name' => 'answer'], $payload['tool_choice']);
    // The forced tool's arguments are the answer, returned as message text.
    $this->assertSame('{"sentiment":"positive"}', $output->getNormalized()->getText());
    $this->assertNull($output->getNormalized()->getTools());
  }

  /**
   * Tests that an image message becomes a base64 image block.
   */
  public function testImagesBecomeBase64Blocks(): void {
    $this->mockRequests([$this->messageResponse([['type' => 'text', 'text' => 'A cat.']])]);

    $message = new ChatMessage('user', 'What is this?');
    $message->setImageFromBinary('binary-bytes', 'image/png');

    $this->backend()->chat(
      new ChatInput([$message]),
      'claude-sonnet-4-5',
      $this->anthropicServer(),
    );

    $blocks = $this->lastPayload()['messages'][0]['content'];
    $image = end($blocks);
    $this->assertSame('image', $image['type']);
    $this->assertSame('image/png', $image['source']['media_type']);
    // Raw base64, without the data: URL scheme OpenAI uses.
    $this->assertSame(base64_encode('binary-bytes'), $image['source']['data']);
  }

  /**
   * Tests HTTP failures mapping to the AI core exception callers expect.
   */
  public function testRateLimitMapsToRateLimitException(): void {
    $this->mockRequests([
      new Response(429, [], (string) json_encode([
        'error' => ['type' => 'rate_limit_error', 'message' => 'Slow down'],
      ])),
    ]);

    $this->expectException('Drupal\ai\Exception\AiRateLimitException');
    $this->expectExceptionMessage('Slow down');
    $this->backend()->chat(
      new ChatInput([new ChatMessage('user', 'Hi')]),
      'claude-sonnet-4-5',
      $this->anthropicServer(),
    );
  }

  /**
   * Tests billing errors mapping to a quota exception.
   */
  public function testBillingErrorMapsToQuotaException(): void {
    $this->mockRequests([
      new Response(400, [], (string) json_encode([
        'error' => ['type' => 'billing_error', 'message' => 'Credit balance too low'],
      ])),
    ]);

    $this->expectException('Drupal\ai\Exception\AiQuotaException');
    $this->backend()->chat(
      new ChatInput([new ChatMessage('user', 'Hi')]),
      'claude-sonnet-4-5',
      $this->anthropicServer(),
    );
  }

  /**
   * Tests the server-sent event parser and the streamed iterator.
   */
  public function testStreamingYieldsTextAndUsage(): void {
    $events = implode("\n", [
      'event: message_start',
      'data: {"type":"message_start","message":{"usage":{"input_tokens":9}}}',
      '',
      'event: content_block_delta',
      'data: {"type":"content_block_delta","delta":{"type":"thinking_delta","thinking":"hmm"}}',
      '',
      'event: content_block_delta',
      'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hola"}}',
      '',
      'event: content_block_delta',
      'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":" mundo"}}',
      '',
      'event: message_delta',
      'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":4}}',
      '',
      'event: message_stop',
      'data: {"type":"message_stop"}',
      '',
    ]);

    $iterator = new AnthropicStreamedChatMessageIterator(
      AnthropicStreamedChatMessageIterator::readEvents(Utils::streamFor($events)),
    );

    $text = '';
    $input_tokens = NULL;
    $output_tokens = NULL;
    foreach ($iterator as $message) {
      $text .= $message->getText();
      $input_tokens ??= $message->getInputTokenUsage() ?: NULL;
      $output_tokens ??= $message->getOutputTokenUsage() ?: NULL;
    }

    // Thinking deltas are internals and must not leak into the answer.
    $this->assertSame('Hola mundo', $text);
    $this->assertSame(9, $input_tokens);
    $this->assertSame(4, $output_tokens);
  }

}
