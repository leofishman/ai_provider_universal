<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Plugin\ServerBackend;

use Drupal\ai_provider_universal\Plugin\ServerBackend\OpenRouter;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests OpenRouter capability and metadata detection.
 *
 * @group ai_provider_universal
 */
#[CoversClass(OpenRouter::class)]
#[Group('ai_provider_universal')]
final class OpenRouterTest extends UnitTestCase {

  /**
   * Builds the plugin with unused mocked services.
   */
  private function backend(): OpenRouter {
    return new OpenRouter(
      [],
      'openrouter',
      [],
      $this->createMock(ClientFactory::class),
      $this->createMock(StateInterface::class),
    );
  }

  /**
   * Tests operation type detection from output_modalities and name fallback.
   */
  public function testDetectOperationTypes(): void {
    $backend = $this->backend();

    $image = ['id' => 'google/gemini-3-pro-image', 'architecture' => ['output_modalities' => ['image', 'text']]];
    $this->assertSame(['text_to_image'], $backend->detectOperationTypes($image));

    $chat = ['id' => 'openai/gpt-5.2', 'architecture' => ['output_modalities' => ['text']]];
    $this->assertSame(['chat'], $backend->detectOperationTypes($chat));

    $moderation = ['id' => 'meta-llama/llama-guard-4-12b', 'architecture' => ['output_modalities' => ['text']]];
    $this->assertSame(['moderation'], $backend->detectOperationTypes($moderation));
  }

  /**
   * Tests pricing conversion (USD/token to USD/1M) and context length.
   */
  public function testDetectModelMetadata(): void {
    $backend = $this->backend();

    $entry = [
      'id' => 'openai/gpt-5.2',
      'context_length' => 262144,
      'pricing' => ['prompt' => '0.0000025', 'completion' => '0.00001'],
    ];
    $this->assertSame(
      ['cost_input' => 2.5, 'cost_output' => 10.0, 'context_length' => 262144],
      $backend->detectModelMetadata($entry),
    );

    // Entries without pricing/context stay empty (fields unset, not zero).
    $this->assertSame([], $backend->detectModelMetadata(['id' => 'x']));
  }

}
