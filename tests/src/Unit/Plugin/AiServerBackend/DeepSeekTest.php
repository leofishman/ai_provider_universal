<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\ai_provider_universal\Plugin\AiServerBackend\DeepSeek;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests DeepSeek endpoint, list prices and the off-peak multiplier.
 */
#[CoversClass(DeepSeek::class)]
#[Group('ai_provider_universal')]
final class DeepSeekTest extends TestCase {

  /**
   * Builds the plugin with unused mocked services.
   */
  private function backend(): DeepSeek {
    return new DeepSeek(
      [],
      'deepseek',
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
    $this->assertSame('https://api.deepseek.com', $this->backend()->getBaseUri($server));
  }

  /**
   * List prices come from the table; the discount is not baked in.
   */
  public function testDetectModelMetadata(): void {
    $meta = $this->backend()->detectModelMetadata(['id' => 'deepseek-reasoner']);
    $this->assertSame(0.55, $meta['cost_input']);
    $this->assertSame(2.19, $meta['cost_output']);
    $this->assertArrayNotHasKey('off_peak', $meta);
  }

  /**
   * Off-peak window is 16:30-00:30 UTC, per model discount.
   */
  public function testPriceMultiplier(): void {
    $backend = $this->backend();
    $peak = strtotime('2026-08-17 12:00:00 UTC');
    $offPeak = strtotime('2026-08-17 20:00:00 UTC');
    $justAfterMidnight = strtotime('2026-08-17 00:15:00 UTC');
    $justAfterWindow = strtotime('2026-08-17 00:45:00 UTC');

    $this->assertSame(1.0, $backend->getPriceMultiplier('deepseek-chat', $peak));
    $this->assertSame(0.5, $backend->getPriceMultiplier('deepseek-chat', $offPeak));
    $this->assertSame(0.25, $backend->getPriceMultiplier('deepseek-reasoner', $offPeak));
    $this->assertSame(0.5, $backend->getPriceMultiplier('deepseek-chat', $justAfterMidnight));
    $this->assertSame(1.0, $backend->getPriceMultiplier('deepseek-chat', $justAfterWindow));
    // Unknown model: no discount.
    $this->assertSame(1.0, $backend->getPriceMultiplier('some-other-model', $offPeak));
  }

}
