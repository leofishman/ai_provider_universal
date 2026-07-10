<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Plugin\AiGuardrail;

use Drupal\ai\Guardrail\Result\PassResult;
use Drupal\ai\Guardrail\Result\RewriteOutputResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai_provider_universal\Plugin\AiGuardrail\AiOriginMarker;
use Drupal\ai_provider_universal\Plugin\AiGuardrail\DisclosureSuffix;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the disclosure suffix and AI-origin marker guardrail plugins.
 */
#[CoversClass(DisclosureSuffix::class)]
#[CoversClass(AiOriginMarker::class)]
#[Group('ai_provider_universal')]
final class DisclosureSuffixTest extends TestCase {

  /**
   * Creates a configured DisclosureSuffix plugin.
   */
  protected function createSuffixPlugin(string $suffix): DisclosureSuffix {
    return new DisclosureSuffix(
      ['suffix_text' => $suffix],
      'universal_disclosure_suffix',
      ['label' => 'AI disclosure suffix (Universal)'],
    );
  }

  /**
   * Wraps a plain assistant message into a ChatOutput.
   */
  protected function chatOutput(string $text): ChatOutput {
    return new ChatOutput(new ChatMessage('assistant', $text), $text, []);
  }

  /**
   * The configured suffix is appended after a blank line.
   */
  public function testSuffixAppended(): void {
    $plugin = $this->createSuffixPlugin('AI generated.');
    $result = $plugin->processOutput($this->chatOutput('The answer is 42.'));

    $this->assertInstanceOf(RewriteOutputResult::class, $result);
    $this->assertSame("The answer is 42.\n\nAI generated.", $result->getMessage());
  }

  /**
   * An already-present suffix is not appended twice (escalation re-runs).
   */
  public function testSuffixNotDoubled(): void {
    $plugin = $this->createSuffixPlugin('AI generated.');
    $result = $plugin->processOutput($this->chatOutput("Answer.\n\nAI generated."));

    $this->assertInstanceOf(PassResult::class, $result);
  }

  /**
   * Empty configuration and empty answers pass untouched.
   */
  public function testEmptyConfigurationAndEmptyTextPass(): void {
    $unconfigured = new DisclosureSuffix([], 'universal_disclosure_suffix', ['label' => 'x']);
    $this->assertInstanceOf(PassResult::class, $unconfigured->processOutput($this->chatOutput('Hello.')));

    $plugin = $this->createSuffixPlugin('AI generated.');
    $this->assertInstanceOf(PassResult::class, $plugin->processOutput($this->chatOutput('')));
  }

  /**
   * Streamed output is passed through, never buffered for the suffix.
   */
  public function testStreamedOutputPasses(): void {
    $plugin = $this->createSuffixPlugin('AI generated.');
    $stream = $this->createMock(StreamedChatMessageIteratorInterface::class);
    $result = $plugin->processOutput(new ChatOutput($stream, '', []));

    $this->assertInstanceOf(PassResult::class, $result);
  }

  /**
   * Input processing is a no-op for suffix guardrails.
   */
  public function testInputPasses(): void {
    $plugin = $this->createSuffixPlugin('AI generated.');
    $result = $plugin->processInput(new ChatInput([new ChatMessage('user', 'Hi')]));

    $this->assertInstanceOf(PassResult::class, $result);
  }

  /**
   * The marker joins with a single newline and stays machine-readable.
   */
  public function testMarkerAppended(): void {
    $marker = '<!-- ai-origin: generated; digitalSourceType=trainedAlgorithmicMedia -->';
    $plugin = new AiOriginMarker(
      ['suffix_text' => $marker],
      'universal_ai_origin_marker',
      ['label' => 'AI origin marker (Universal)'],
    );
    $result = $plugin->processOutput($this->chatOutput('Answer.'));

    $this->assertInstanceOf(RewriteOutputResult::class, $result);
    $this->assertSame("Answer.\n" . $marker, $result->getMessage());
  }

}
