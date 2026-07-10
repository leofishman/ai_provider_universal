<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\ai_provider_universal\Plugin\AiServerBackend\Ollama;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Ollama capability and metadata detection from /api/show fields.
 */
#[CoversClass(Ollama::class)]
#[Group('ai_provider_universal')]
final class OllamaTest extends UnitTestCase {

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
  }

}
