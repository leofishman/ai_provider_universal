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
 * Tests Groq endpoint, capability and live-catalog metadata detection.
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
   * Operation types from modalities and guard-id heuristics.
   */
  public function testDetectOperationTypes(): void {
    $backend = $this->backend();

    $this->assertSame(
      ['speech_to_text'],
      $backend->detectOperationTypes([
        'id' => 'whisper-large-v3',
        'output_modalities' => ['transcription'],
        'input_modalities' => ['audio'],
      ]),
    );
    $this->assertSame(
      ['text_to_speech'],
      $backend->detectOperationTypes([
        'id' => 'canopylabs/orpheus-v1-english',
        'output_modalities' => ['speech'],
        'input_modalities' => ['text'],
      ]),
    );
    $this->assertSame(
      ['moderation'],
      $backend->detectOperationTypes([
        'id' => 'meta-llama/llama-prompt-guard-2-86m',
        'output_modalities' => ['text'],
      ]),
    );
    $this->assertSame(
      ['moderation'],
      $backend->detectOperationTypes([
        'id' => 'openai/gpt-oss-safeguard-20b',
        'output_modalities' => ['text'],
      ]),
    );
    $this->assertSame(
      ['chat'],
      $backend->detectOperationTypes([
        'id' => 'llama-3.3-70b-versatile',
        'output_modalities' => ['text'],
        'supported_features' => ['tools', 'json_mode'],
      ]),
    );
  }

  /**
   * Metadata: live USD/token pricing × 1M and context_length.
   */
  public function testDetectModelMetadata(): void {
    $backend = $this->backend();

    // Shape captured from GET https://api.groq.com/openai/v1/models.
    $entry = [
      'id' => 'llama-3.1-8b-instant',
      'context_length' => 131072,
      'context_window' => 131072,
      'pricing' => [
        'prompt' => '0.00000005',
        'completion' => '0.00000008',
        'input_cache_read' => '0.000000025',
      ],
      'supported_features' => ['tools', 'json_mode'],
    ];
    $this->assertSame(
      [
        'cost_input' => 0.05,
        'cost_output' => 0.08,
        'context_length' => 131072,
      ],
      $backend->detectModelMetadata($entry),
    );

    // TTS models may only publish a prompt (per-character) rate.
    $tts = [
      'id' => 'canopylabs/orpheus-v1-english',
      'context_length' => 4000,
      'pricing' => ['prompt' => '0.000022'],
    ];
    $this->assertSame(
      [
        'cost_input' => 22.0,
        'context_length' => 4000,
      ],
      $backend->detectModelMetadata($tts),
    );

    // No pricing (e.g. compound systems): still get context.
    $this->assertSame(
      ['context_length' => 131072],
      $backend->detectModelMetadata([
        'id' => 'groq/compound',
        'context_window' => 131072,
      ]),
    );

    $this->assertSame([], $backend->detectModelMetadata(['id' => 'x']));
  }

}
