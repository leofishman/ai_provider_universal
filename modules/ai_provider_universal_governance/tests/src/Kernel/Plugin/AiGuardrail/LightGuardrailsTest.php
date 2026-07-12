<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_governance\Kernel\Plugin\AiGuardrail;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_provider_universal_factcheck\Service\AiDetector;
use Drupal\ai_provider_universal_factcheck\Service\FactChecker;
use Drupal\ai_provider_universal_governance\Plugin\AiGuardrail\AiLikelihoodGuardrail;
use Drupal\ai_provider_universal_governance\Plugin\AiGuardrail\FactcheckGuardrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the light AI-likelihood and factcheck Guardrail plugins.
 *
 * The underlying factcheck services are mocked: these tests cover the
 * threshold/stop semantics and the soft dependency, not the LLM pipeline.
 *
 * @group ai_provider_universal
 */
#[CoversClass(AiLikelihoodGuardrail::class)]
#[CoversClass(FactcheckGuardrail::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class LightGuardrailsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'ai_provider_universal',
    'ai_provider_universal_governance',
    'ai_provider_universal_factcheck',
  ];

  /**
   * Creates the plugin with a mocked detector/checker in the container.
   */
  protected function plugin(string $plugin_id, object $service_mock, string $service_id): object {
    $this->container->set($service_id, $service_mock);
    return $this->container->get('plugin.manager.ai_guardrail')->createInstance($plugin_id);
  }

  /**
   * Single-message chat output with the given text.
   */
  protected function chatOutput(string $text): ChatOutput {
    $message = new ChatMessage('assistant', $text);
    return new ChatOutput($message, [], []);
  }

  /**
   * Likelihood at/above threshold stops with a normalized score.
   */
  public function testLikelihoodStopsAboveThreshold(): void {
    $detector = $this->createMock(AiDetector::class);
    $detector->method('isConfigured')->willReturn(TRUE);
    $detector->method('detect')->willReturn(['score' => 90, 'rationale' => 'uniform rhythm']);
    $plugin = $this->plugin('universal_ai_likelihood', $detector, AiDetector::class);

    $this->assertTrue($plugin->isAvailable());
    $result = $plugin->processOutput($this->chatOutput('Suspicious text.'));
    $this->assertInstanceOf(StopResult::class, $result);
    $this->assertSame(0.9, $result->getScore());
    $this->assertStringContainsString('uniform rhythm', $result->getMessage());
  }

  /**
   * Likelihood below threshold passes; so do detection failures.
   */
  public function testLikelihoodPasses(): void {
    $detector = $this->createMock(AiDetector::class);
    $detector->method('isConfigured')->willReturn(TRUE);
    $detector->method('detect')->willReturnOnConsecutiveCalls(
      ['score' => 30, 'rationale' => ''],
      NULL,
    );
    $plugin = $this->plugin('universal_ai_likelihood', $detector, AiDetector::class);

    $this->assertFalse($plugin->processOutput($this->chatOutput('Human text.'))->stop());
    // A failed detection never blocks traffic.
    $this->assertFalse($plugin->processOutput($this->chatOutput('Anything.'))->stop());
  }

  /**
   * The pre-generate side scores the last user message.
   */
  public function testLikelihoodScoresInput(): void {
    $detector = $this->createMock(AiDetector::class);
    $detector->method('isConfigured')->willReturn(TRUE);
    $detector->expects($this->once())->method('detect')
      ->with('Pasted essay.')
      ->willReturn(['score' => 95, 'rationale' => '']);
    $plugin = $this->plugin('universal_ai_likelihood', $detector, AiDetector::class);

    $input = new ChatInput([
      new ChatMessage('user', 'Earlier message.'),
      new ChatMessage('user', 'Pasted essay.'),
    ]);
    $this->assertTrue($plugin->processInput($input)->stop());
  }

  /**
   * An unconfigured detector makes the plugin unavailable and a no-op.
   */
  public function testLikelihoodUnavailableWithoutDetector(): void {
    $detector = $this->createMock(AiDetector::class);
    $detector->method('isConfigured')->willReturn(FALSE);
    $plugin = $this->plugin('universal_ai_likelihood', $detector, AiDetector::class);

    $this->assertFalse($plugin->isAvailable());
    $this->assertFalse($plugin->processOutput($this->chatOutput('Text.'))->stop());
  }

  /**
   * A support score below the minimum stops with the inverse as score.
   */
  public function testFactcheckStopsBelowMinScore(): void {
    $checker = $this->createMock(FactChecker::class);
    $checker->method('isConfigured')->willReturn(TRUE);
    $checker->method('verify')->willReturn(['score' => 0.25, 'claims' => []]);
    $plugin = $this->plugin('universal_factcheck', $checker, 'ai_provider_universal_factcheck.checker');

    $result = $plugin->processOutput($this->chatOutput('Dubious claims.'));
    $this->assertInstanceOf(StopResult::class, $result);
    $this->assertSame(0.75, $result->getScore());
  }

  /**
   * Sufficient support passes; input processing is always a pass.
   */
  public function testFactcheckPasses(): void {
    $checker = $this->createMock(FactChecker::class);
    $checker->method('isConfigured')->willReturn(TRUE);
    $checker->method('verify')->willReturn(['score' => 0.9, 'claims' => []]);
    $plugin = $this->plugin('universal_factcheck', $checker, 'ai_provider_universal_factcheck.checker');

    $this->assertFalse($plugin->processOutput($this->chatOutput('Solid answer.'))->stop());
    $this->assertFalse($plugin->processInput(new ChatInput([new ChatMessage('user', 'Hi')]))->stop());
  }

  /**
   * Empty responses (e.g. pure tool calls) are never verified.
   */
  public function testFactcheckSkipsEmptyText(): void {
    $checker = $this->createMock(FactChecker::class);
    $checker->method('isConfigured')->willReturn(TRUE);
    $checker->expects($this->never())->method('verify');
    $plugin = $this->plugin('universal_factcheck', $checker, 'ai_provider_universal_factcheck.checker');

    $this->assertFalse($plugin->processOutput($this->chatOutput(''))->stop());
  }

}
