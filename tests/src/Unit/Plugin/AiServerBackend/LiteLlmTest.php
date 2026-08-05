<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Plugin\AiServerBackend\LiteLlm;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests LiteLLM capability and metadata detection.
 *
 * @group ai_provider_universal
 */
#[CoversClass(LiteLlm::class)]
#[Group('ai_provider_universal')]
final class LiteLlmTest extends TestCase {

  /**
   * Builds the plugin with unused mocked services.
   */
  private function backend(): LiteLlm {
    return new LiteLlm(
      [],
      'litellm',
      [],
      $this->createMock(ClientFactory::class),
      $this->createMock(StateInterface::class),
    );
  }

  /**
   * Tests operation type detection from model_info.mode and name fallback.
   */
  public function testDetectOperationTypes(): void {
    $backend = $this->backend();

    $this->assertSame(['embeddings'], $backend->detectOperationTypes([
      'id' => 'text-embedding-3-small',
      'model_info' => ['mode' => 'embedding'],
    ]));
    $this->assertSame(['chat'], $backend->detectOperationTypes([
      'id' => 'gpt-5.2',
      'model_info' => ['mode' => 'chat'],
    ]));
    $this->assertSame(['text_to_image'], $backend->detectOperationTypes([
      'id' => 'dall-e-3',
      'model_info' => ['mode' => 'image_generation'],
    ]));
    // No mode: falls back to generic name heuristics.
    $this->assertSame(['moderation'], $backend->detectOperationTypes([
      'id' => 'llama-guard-4',
    ]));
  }

  /**
   * Tests cost conversion (USD/token to USD/1M) and context length.
   */
  public function testDetectModelMetadata(): void {
    $backend = $this->backend();

    $this->assertSame(
      ['cost_input' => 2.5, 'cost_output' => 10.0, 'context_length' => 128000],
      $backend->detectModelMetadata([
        'id' => 'gpt-5.2',
        'model_info' => [
          'input_cost_per_token' => 0.0000025,
          'output_cost_per_token' => 0.00001,
          'max_input_tokens' => 128000,
        ],
      ]),
    );
    $this->assertSame([], $backend->detectModelMetadata(['id' => 'x']));
  }

}
