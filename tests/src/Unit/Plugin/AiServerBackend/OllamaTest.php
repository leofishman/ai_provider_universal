<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\ai_provider_universal\Plugin\AiServerBackend\Ollama;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Ollama capability and metadata detection from /api/show fields.
 */
#[CoversClass(Ollama::class)]
#[Group('ai_provider_universal')]
final class OllamaTest extends TestCase {

  /**
   * Builds the plugin with unused mocked services.
   */
  private function backend(): Ollama {
    return new Ollama(
      [],
      'ollama',
      [],
      $this->createMock(ClientFactory::class),
      $this->createMock(StateInterface::class),
    );
  }

  /**
   * Operation types prefer structured capabilities / family over name alone.
   */
  public function testDetectOperationTypes(): void {
    $backend = $this->backend();

    $embedCap = [
      'id' => 'nomic-embed-text',
      'capabilities' => ['embedding'],
    ];
    $this->assertSame(['embeddings'], $backend->detectOperationTypes($embedCap));

    $bertFamily = [
      'id' => 'custom-encoder',
      'details' => ['family' => 'nomic-bert'],
    ];
    $this->assertSame(['embeddings'], $backend->detectOperationTypes($bertFamily));

    $chat = [
      'id' => 'llama3.2:1b',
      'capabilities' => ['completion'],
      'details' => ['family' => 'llama'],
    ];
    $this->assertSame(['chat'], $backend->detectOperationTypes($chat));

    // Name heuristics still catch moderation models without capabilities.
    $guard = ['id' => 'llama-guard3:8b'];
    $this->assertSame(['moderation'], $backend->detectOperationTypes($guard));
  }

  /**
   * Metadata: free costs, num_ctx over architecture max, model_info fallback.
   */
  public function testDetectModelMetadata(): void {
    $backend = $this->backend();

    $withNumCtx = [
      'id' => 'llama3.2:1b',
      'parameters' => "num_ctx                        8192\nstop                           <|eot_id|>",
      'model_info' => [
        'general.architecture' => 'llama',
        'llama.context_length' => 131072,
      ],
    ];
    $this->assertSame(
      [
        'context_length' => 8192,
        'cost_input' => 0.0,
        'cost_output' => 0.0,
      ],
      $backend->detectModelMetadata($withNumCtx),
    );

    $archOnly = [
      'id' => 'qwen2.5:0.5b',
      'model_info' => [
        'qwen2.context_length' => 32768,
      ],
    ];
    $this->assertSame(
      [
        'context_length' => 32768,
        'cost_input' => 0.0,
        'cost_output' => 0.0,
      ],
      $backend->detectModelMetadata($archOnly),
    );

    // Bare catalog entry: still free, no context.
    $this->assertSame(
      ['cost_input' => 0.0, 'cost_output' => 0.0],
      $backend->detectModelMetadata(['id' => 'mystery']),
    );

    // Cloud models proxied through local Ollama are metered on ollama.com:
    // costs stay unset for the user to fill in.
    $this->assertSame([], $backend->detectModelMetadata(['id' => 'gpt-oss:120b-cloud']));
  }

  /**
   * Discovery enriches the OpenAI catalog with /api/show fields per model.
   *
   * The second model's show call fails; its entry stays bare and discovery
   * still succeeds.
   */
  public function testListModelsEnrichment(): void {
    $mock = new MockHandler([
      // GET /v1/models.
      new Response(200, [], json_encode([
        'data' => [
          ['id' => 'llama3.2:1b'],
          ['id' => 'broken-model'],
        ],
      ])),
      // POST /api/show for llama3.2:1b.
      new Response(200, [], json_encode([
        'details' => ['family' => 'llama'],
        'model_info' => ['llama.context_length' => 131072],
        'capabilities' => ['completion', 'tools'],
      ])),
      // POST /api/show for broken-model.
      new Response(500),
    ]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    $factory = $this->createMock(ClientFactory::class);
    $factory->method('fromOptions')->willReturn($client);

    $backend = new Ollama([], 'ollama', [], $factory, $this->createMock(StateInterface::class));

    $server = $this->createMock(AiUniversalServerInterface::class);
    $server->method('getHostName')->willReturn('http://127.0.0.1');
    $server->method('getPort')->willReturn('11434');
    $server->method('getApiKey')->willReturn('');
    $server->method('getTimeout')->willReturn(30);

    $models = $backend->listModels($server);

    $this->assertCount(2, $models);
    $this->assertSame('llama', $models[0]['details']['family']);
    $this->assertSame(131072, $models[0]['model_info']['llama.context_length']);
    $this->assertSame(['completion', 'tools'], $models[0]['capabilities']);
    // Failed show call leaves the entry bare.
    $this->assertSame(['id' => 'broken-model'], $models[1]);
  }

  /**
   * Native base URI strips the OpenAI /v1 suffix used for chat.
   */
  public function testGetNativeBaseUri(): void {
    $backend = $this->backend();
    $server = $this->createMock(AiUniversalServerInterface::class);
    $server->method('getHostName')->willReturn('http://127.0.0.1');
    $server->method('getPort')->willReturn('11434');

    $method = new \ReflectionMethod(Ollama::class, 'getNativeBaseUri');
    $this->assertSame('http://127.0.0.1:11434', $method->invoke($backend, $server));

    // Empty port defaults to Ollama's 11434.
    $noPort = $this->createMock(AiUniversalServerInterface::class);
    $noPort->method('getHostName')->willReturn('http://127.0.0.1');
    $noPort->method('getPort')->willReturn('');
    $this->assertSame('http://127.0.0.1:11434/v1', $backend->getBaseUri($noPort));
  }

}
