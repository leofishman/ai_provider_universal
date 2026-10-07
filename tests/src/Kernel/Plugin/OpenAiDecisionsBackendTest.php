<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Plugin;

use Drupal\ai\OperationType\Decision\Value\AnswerFactory;
use Drupal\ai\OperationType\Decision\Value\AnswerPrecision;
use Drupal\ai_provider_universal\Backend\AiServerBackendManager;
use Drupal\ai_provider_universal\Plugin\AiServerBackend\OpenAiDecisions;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_provider_universal\Kernel\Traits\HttpClientMockTrait;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the OpenAI Decisions backend's translation to and from System One.
 *
 * The wire format follows the request and response verified against the
 * live API in https://www.drupal.org/project/ai_provider_openai/issues/3622163.
 *
 * @group ai_provider_universal
 */
#[CoversClass(OpenAiDecisions::class)]
#[Group('ai_provider_universal')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
final class OpenAiDecisionsBackendTest extends KernelTestBase {

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
   * One question of each type, in System One's shape.
   */
  protected const QUESTIONS = [
    'half_life' => [
      'type' => 'choice',
      'instructions' => 'how to choose',
      'criteria' => ['high_decay' => 'when to use this', 'medium_decay' => NULL],
    ],
    'is_urgent' => [
      'type' => 'noul',
      'instructions' => 'The message conveys urgency',
    ],
    'severity' => [
      'type' => 'score',
      'instructions' => 'How severe is it',
      'criteria' => ['Cosmetic', 'Blocks work'],
    ],
  ];

  /**
   * Returns the backend plugin instance.
   */
  protected function backend(): OpenAiDecisions {
    /** @var \Drupal\ai_provider_universal\Plugin\AiServerBackend\OpenAiDecisions $backend */
    $backend = $this->container->get(AiServerBackendManager::class)->createInstance('openai_decisions');
    return $backend;
  }

  /**
   * Creates the server entity every test calls against.
   */
  protected function server(): object {
    $server = $this->container->get('entity_type.manager')
      ->getStorage('ai_universal_server')
      ->create([
        'id' => 'openai',
        'label' => 'OpenAI',
        'backend' => 'openai_decisions',
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
   * Queues responses and records the requests that consume them.
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
   * Builds a Decisions API response with the given answers.
   */
  protected function decisionsResponse(array $answers): Response {
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
      'model' => 'gpt-6-luna',
      'answers' => $answers,
      'usage' => ['input_tokens' => 431, 'output_tokens' => 0],
    ]));
  }

  /**
   * Tests the endpoint, Luna's price and the model filter.
   */
  public function testEndpointPriceAndModels(): void {
    // The mock goes in before the backend is created, or it would keep the
    // real HTTP client.
    $this->mockRequests([
      new Response(200, [], (string) json_encode([
        'object' => 'list',
        'data' => [['id' => 'gpt-5-nano'], ['id' => 'gpt-6-luna'], ['id' => 'text-embedding-3-small']],
      ])),
    ]);
    $backend = $this->backend();
    $server = $this->server();

    $this->assertSame('https://api.openai.com/v1', $backend->getBaseUri($server));
    $this->assertSame(0.10, $backend->detectModelMetadata(['id' => 'gpt-6-luna'])['cost_input']);
    $this->assertSame(['gpt-6-luna'], array_column($backend->listModels($server), 'id'));
  }

  /**
   * Tests the request and answer translation for every question type.
   */
  public function testQuestionsAndAnswersAreTranslated(): void {
    $this->mockRequests([
      $this->decisionsResponse([
        [
          'type' => 'choice',
          'name' => 'half_life',
          'choice' => 'medium_decay',
          'probabilities' => [
            ['value' => 'high_decay', 'probability' => 0.04],
            ['value' => 'medium_decay', 'probability' => 0.95],
          ],
          'confidence' => 0.93,
        ],
        ['type' => 'predicate', 'name' => 'is_urgent', 'probability' => 0.88],
        [
          'type' => 'score',
          'name' => 'severity',
          'score' => 0.9,
          'probabilities' => [
            ['value' => 0, 'label' => '0', 'probability' => 0.1],
            ['value' => 1, 'label' => '1', 'probability' => 0.9],
          ],
          'confidence' => 0.8,
        ],
      ]),
    ]);

    $output = $this->backend()->chat('text to classify', 'gpt-6-luna', $this->server(), ['questions' => self::QUESTIONS]);

    $request = end($this->requests);
    $this->assertStringEndsWith('/v1/decisions', (string) $request->getUri());
    $this->assertSame([
      'model' => 'gpt-6-luna',
      'input' => 'text to classify',
      'questions' => [
        [
          'type' => 'choice',
          'name' => 'half_life',
          'instructions' => 'how to choose',
          'choices' => [
            ['value' => 'high_decay', 'description' => 'when to use this'],
            ['value' => 'medium_decay'],
          ],
        ],
        ['type' => 'predicate', 'name' => 'is_urgent', 'instructions' => 'The message conveys urgency'],
        [
          'type' => 'score',
          'name' => 'severity',
          'instructions' => 'How severe is it',
          'levels' => [
            ['label' => '0', 'description' => 'Cosmetic'],
            ['label' => '1', 'description' => 'Blocks work'],
          ],
        ],
      ],
    ], json_decode((string) $request->getBody(), TRUE));

    $answers = json_decode($output->getNormalized()->getText(), TRUE);
    $this->assertSame(['type' => 'noul', 'noul' => 0.88], $answers['is_urgent']);
    $this->assertSame(['high_decay' => 0.04, 'medium_decay' => 0.95], $answers['half_life']['probabilities']);
    $this->assertSame(['Cosmetic', 'Blocks work'], $answers['severity']['legend']);
    $this->assertSame(431, $output->getTokenUsage()->input);

    // On AI 1.6+, the translated answers are valid Decision answers. The
    // choice probabilities sum to 0.99, as in the live sample: they pass
    // only because this backend declares no rounding precision.
    if (class_exists(AnswerFactory::class)) {
      $decimals = OpenAiDecisions::DECISION_PRECISION;
      $precision = $decimals === NULL ? NULL : new AnswerPrecision($decimals);
      foreach ($output->getRawOutput()['answers'] as $id => $answer) {
        $this->assertSame($answer['type'], AnswerFactory::fromArray($answer, $precision)->getType(), $id);
      }
    }
  }

  /**
   * Tests that a refusal leaves the question unanswered.
   */
  public function testRefusalIsLeftUnanswered(): void {
    $this->mockRequests([
      $this->decisionsResponse([
        ['type' => 'refusal', 'name' => 'is_urgent'],
      ]),
    ]);

    $output = $this->backend()->chat('text', 'gpt-6-luna', $this->server(), ['questions' => ['is_urgent' => self::QUESTIONS['is_urgent']]]);
    $this->assertSame([], json_decode($output->getNormalized()->getText(), TRUE));
  }

  /**
   * Tests that OpenAI's error shape maps to AI core exceptions.
   */
  public function testOpenAiErrorsMapToAiCoreExceptions(): void {
    $this->mockRequests([
      new Response(429, [], (string) json_encode([
        'error' => [
          'type' => 'insufficient_quota',
          'code' => 'insufficient_quota',
          'message' => 'You exceeded your current quota.',
        ],
      ])),
    ]);

    $this->expectException('Drupal\ai\Exception\AiQuotaException');
    $this->expectExceptionMessage('You exceeded your current quota.');
    $this->backend()->chat('Hi', 'gpt-6-luna', $this->server(), ['questions' => ['is_urgent' => self::QUESTIONS['is_urgent']]]);
  }

}
