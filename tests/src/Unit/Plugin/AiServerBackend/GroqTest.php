<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\ai_provider_universal\Plugin\AiServerBackend\Groq;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Groq endpoint, capability and metadata detection.
 */
#[CoversClass(Groq::class)]
#[Group('ai_provider_universal')]
final class GroqTest extends UnitTestCase {

  /**
   * Builds the plugin with unused mocked services.
   */
  private function backend(): Groq {
    return new Groq(
      [],
      'groq',
      [],
      $this->createMock(ClientFactory::class),
      $this->createMock(StateInterface::class),
    );
  }

  /**
   * Fixed endpoint ignores the server host field.
   */
  public function testGetBaseUriIsFixed(): void {
    $server = $this->createMock(AiUniversalServerInterface::class);
    $server->expects($this->never())->method('getHostName');
    $this->assertSame(
      'https://api.groq.com/openai/v1',
      $this->backend()->getBaseUri($server),
    );
  }

  /**
   * Operation types: whisper, prompt-guard, and chat fallbacks.
   */
  public function testDetectOperationTypes(): void {
    $backend = $this->backend();

    $this->assertSame(
      ['speech_to_text'],
      $backend->detectOperationTypes(['id' => 'whisper-large-v3-turbo']),
    );
    $this->assertSame(
      ['moderation'],
      $backend->detectOperationTypes(['id' => 'meta-llama/llama-prompt-guard-2-86m']),
    );
    $this->assertSame(
      ['moderation'],
      $backend->detectOperationTypes(['id' => 'openai/gpt-oss-safeguard-20b']),
    );
    $this->assertSame(
      ['chat'],
      $backend->detectOperationTypes(['id' => 'llama-3.3-70b-versatile']),
    );
  }

  /**
   * Metadata: specific patterns, no false match of gpt-oss-20b on safeguard.
   */
  public function testDetectModelMetadata(): void {
    $backend = $this->backend();

    $this->assertSame(
      [
        'cost_input' => 0.05,
        'cost_output' => 0.08,
        'quality_tier' => 3,
        'context_length' => 131072,
      ],
      $backend->detectModelMetadata(['id' => 'llama-3.1-8b-instant']),
    );

    $this->assertSame(
      [
        'cost_input' => 0.59,
        'cost_output' => 0.79,
        'quality_tier' => 4,
        'context_length' => 131072,
      ],
      $backend->detectModelMetadata(['id' => 'llama-3.3-70b-versatile']),
    );

    // Safeguard must not pick up the plain gpt-oss-20b row.
    $this->assertSame(
      [
        'cost_input' => 0.075,
        'cost_output' => 0.30,
        'quality_tier' => 3,
        'context_length' => 131072,
      ],
      $backend->detectModelMetadata(['id' => 'openai/gpt-oss-safeguard-20b']),
    );

    $this->assertSame(
      [
        'cost_input' => 0.075,
        'cost_output' => 0.30,
        'quality_tier' => 3,
        'context_length' => 131072,
      ],
      $backend->detectModelMetadata(['id' => 'openai/gpt-oss-20b']),
    );

    // Whisper is audio-hour priced — only tier is prefilled.
    $this->assertSame(
      ['quality_tier' => 3],
      $backend->detectModelMetadata(['id' => 'whisper-large-v3']),
    );

    // Unknown id: empty (parent has nothing for bare catalog rows).
    $this->assertSame([], $backend->detectModelMetadata(['id' => 'future-model-xyz']));
  }

}
