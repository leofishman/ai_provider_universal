<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Plugin;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_provider_universal\Backend\AiInferenceBackendInterface;
use Drupal\ai_provider_universal\Backend\AiServerBackendManager;
use Drupal\ai_provider_universal\Plugin\AiServerBackend\TypeSafe;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_provider_universal\Kernel\Traits\HttpClientMockTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the native TypeSafe (Jev) backend and its request/response mapping.
 *
 * @group ai_provider_universal
 */
#[CoversClass(TypeSafe::class)]
#[Group('ai_provider_universal')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
final class TypeSafeBackendTest extends KernelTestBase {

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
   * A noul question set, as it would sit in the model's extra parameters.
   */
  protected const QUESTIONS = [
    'is_urgent' => [
      'type' => 'noul',
      'instructions' => 'The message conveys urgency',
    ],
  ];

  /**
   * Creates the server entity every test calls against.
   */
  protected function typeSafeServer(): object {
    $server = $this->container->get('entity_type.manager')
      ->getStorage('ai_universal_server')
      ->create([
        'id' => 'jev',
        'label' => 'TypeSafe',
        'backend' => 'typesafe',
        'host_name' => '',
        'port' => '',
        'api_key' => '',
        'timeout' => 60,
        'operation_types' => [],
        'model_filter' => '',
      ]);
    $server->save();
    return $server;
  }

  /**
   * Returns the backend plugin instance.
   */
  protected function backend(): TypeSafe {
    /** @var \Drupal\ai_provider_universal\Plugin\AiServerBackend\TypeSafe $backend */
    $backend = $this->container->get(AiServerBackendManager::class)->createInstance('typesafe');
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
    $stack = $this->container->get('http_client_factory')->fromOptions([])->getConfig('handler');
    $stack->push(function (callable $handler) {
      return function ($request, array $options) use ($handler) {
        $this->requests[] = $request;
        return $handler($request, $options);
      };
    });
  }

  /**
   * Builds a System One response body.
   */
  protected function systemOneResponse(): Response {
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
      'model' => 'jev-1.13.0',
      'answers' => ['is_urgent' => ['type' => 'noul', 'noul' => 0.97]],
      'usage' => ['input_tokens' => 30, 'output_tokens' => 1],
    ]));
  }

  /**
   * Tests that the backend is discoverable and declares native inference.
   */
  public function testBackendIsRegisteredAsInferenceCapable(): void {
    $backend = $this->backend();
    $this->assertInstanceOf(AiInferenceBackendInterface::class, $backend);
    $this->assertSame('https://api.typesafe.ai/v1', $backend->getBaseUri($this->typeSafeServer()));
    $this->assertSame(['chat'], $backend->detectOperationTypes(['id' => 'jev-latest']));
    $this->assertSame(0.042, $backend->detectModelMetadata(['id' => 'jev-latest'])['cost_input']);
    // Self-hosted models on the same protocol are not billed at Jev's price.
    $this->assertArrayNotHasKey('cost_input', $backend->detectModelMetadata(['id' => 'laya']));
  }

  /**
   * Tests that model cards keyed by name become catalog entries.
   */
  public function testListModelsUsesCardName(): void {
    $this->mockRequests([
      new Response(200, [], (string) json_encode([
        ['name' => 'jev-latest', 'description' => 'Jev', 'release_date' => '2026-01-01'],
      ])),
    ]);

    $models = $this->backend()->listModels($this->typeSafeServer());
    $this->assertSame(['jev-latest'], array_column($models, 'id'));
    $this->assertStringEndsWith('/v1/models', (string) $this->requests[0]->getUri());
  }

  /**
   * Tests state, questions from extra parameters, answers and usage.
   */
  public function testChatSendsStateAndQuestions(): void {
    $this->mockRequests([$this->systemOneResponse()]);

    $output = $this->backend()->chat(
      new ChatInput([new ChatMessage('user', 'Help ASAP, I am losing sales')]),
      'jev-latest',
      $this->typeSafeServer(),
      ['questions' => self::QUESTIONS, 'temperature' => 0.2],
    );

    $payload = $this->lastPayload();
    $this->assertStringEndsWith('/v1/systemone', (string) end($this->requests)->getUri());
    // Generic chat parameters are not forwarded.
    $this->assertSame([
      'model' => 'jev-latest',
      'state' => 'Help ASAP, I am losing sales',
      'questions' => self::QUESTIONS,
    ], $payload);

    $answers = json_decode($output->getNormalized()->getText(), TRUE);
    $this->assertSame(0.97, $answers['is_urgent']['noul']);
    $this->assertSame(30, $output->getTokenUsage()->input);
    $this->assertSame(31, $output->getTokenUsage()->total);
  }

  /**
   * Tests per-call questions in the system prompt and multi-turn state.
   */
  public function testQuestionsFromSystemPromptAndConversationState(): void {
    $this->mockRequests([$this->systemOneResponse()]);

    $this->backend()->chat(
      [
        ['role' => 'system', 'content' => json_encode(self::QUESTIONS)],
        ['role' => 'user', 'content' => 'It broke'],
        ['role' => 'assistant', 'content' => 'What broke?'],
        ['role' => 'user', 'content' => 'Stripe'],
      ],
      'jev-latest',
      $this->typeSafeServer(),
    );

    $payload = $this->lastPayload();
    $this->assertSame(self::QUESTIONS, $payload['questions']);
    $this->assertSame("user: It broke\nassistant: What broke?\nuser: Stripe", $payload['state']);
  }

  /**
   * Tests the system prompt as AI core delivers it: duplicated, mixed.
   */
  public function testDuplicatedAndPlainTextSystemPartsAreTolerated(): void {
    $this->mockRequests([$this->systemOneResponse()]);

    $json = (string) json_encode(self::QUESTIONS);
    $input = new ChatInput([
      new ChatMessage('system', 'You are a triage assistant.'),
      new ChatMessage('system', $json),
      new ChatMessage('user', 'Help ASAP'),
    ]);
    $input->setSystemPrompt($json);
    $this->backend()->chat($input, 'jev-latest', $this->typeSafeServer());

    $this->assertSame(self::QUESTIONS, $this->lastPayload()['questions']);
  }

  /**
   * Tests that per-call questions override the model's default set.
   */
  public function testPerCallQuestionsOverrideModelDefaults(): void {
    $this->mockRequests([$this->systemOneResponse()]);

    $perCall = ['complex' => ['type' => 'noul', 'instructions' => 'The task needs reasoning']];
    $this->backend()->chat(
      [
        ['role' => 'system', 'content' => json_encode($perCall)],
        ['role' => 'user', 'content' => 'Hi'],
      ],
      'jev-latest',
      $this->typeSafeServer(),
      ['questions' => self::QUESTIONS],
    );

    $this->assertSame($perCall, $this->lastPayload()['questions']);
  }

  /**
   * Tests that a call without questions fails before any request.
   */
  public function testMissingQuestionsIsRefused(): void {
    $this->mockRequests([]);
    $this->expectException('Drupal\ai\Exception\AiRequestErrorException');
    $this->expectExceptionMessage('TypeSafe needs questions');
    $this->backend()->chat('Hi', 'jev-latest', $this->typeSafeServer(), []);
  }

  /**
   * Tests that streaming is refused rather than faked.
   */
  public function testStreamingIsRefused(): void {
    $this->expectException('Drupal\ai\Exception\AiMissingFeatureException');
    $this->backend()->chat('Hi', 'jev-latest', $this->typeSafeServer(), ['questions' => self::QUESTIONS], TRUE);
  }

  /**
   * Tests HTTP failures mapping to the AI core exception callers expect.
   */
  public function testErrorsMapToAiCoreExceptions(): void {
    $this->mockRequests([
      new Response(403, [], (string) json_encode([
        'detail' => ['error_type' => 'authentication_error', 'message' => 'Must supply an API key!'],
      ])),
    ]);

    $this->expectException('Drupal\ai\Exception\AiSetupFailureException');
    $this->expectExceptionMessage('Must supply an API key!');
    $this->backend()->chat('Hi', 'jev-latest', $this->typeSafeServer(), ['questions' => self::QUESTIONS]);
  }

}
