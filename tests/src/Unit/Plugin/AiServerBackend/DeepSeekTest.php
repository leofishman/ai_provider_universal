<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\ai_provider_universal\Plugin\AiServerBackend\DeepSeek;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Site\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // No status service: the built-in schedule applies.
    new Settings(['ai_provider_universal_deepseek_status_url' => '']);
  }

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
   * List prices come from the table, at the peak rate.
   */
  public function testDetectModelMetadata(): void {
    $meta = $this->backend()->detectModelMetadata(['id' => 'deepseek-v4-pro']);
    $this->assertSame(1.32, $meta['cost_input']);
    $this->assertSame(3.96, $meta['cost_output']);
  }

  /**
   * Peak is 01-04 and 06-10 UTC on weekdays; the rest costs half.
   */
  public function testPriceMultiplier(): void {
    $backend = $this->backend();
    // Monday 2026-08-17.
    $this->assertSame(1.0, $backend->getPriceMultiplier('deepseek-flash', strtotime('2026-08-17 02:00:00 UTC')));
    $this->assertSame(1.0, $backend->getPriceMultiplier('deepseek-v4-pro', strtotime('2026-08-17 09:59:00 UTC')));
    $this->assertSame(0.5, $backend->getPriceMultiplier('deepseek-flash', strtotime('2026-08-17 05:00:00 UTC')));
    $this->assertSame(0.5, $backend->getPriceMultiplier('deepseek-flash', strtotime('2026-08-17 10:00:00 UTC')));
    $this->assertSame(0.5, $backend->getPriceMultiplier('deepseek-flash', strtotime('2026-08-17 00:59:00 UTC')));
    // Saturday 2026-08-22, inside a weekday peak window.
    $this->assertSame(0.5, $backend->getPriceMultiplier('deepseek-flash', strtotime('2026-08-22 02:00:00 UTC')));
    // Thursday 2026-10-01, National Day.
    $this->assertSame(0.5, $backend->getPriceMultiplier('deepseek-flash', strtotime('2026-10-01 02:00:00 UTC')));
    // Versioned ids get the same price entry.
    $this->assertSame(0.5, $backend->getPriceMultiplier('deepseek-v4.1-flash', strtotime('2026-08-17 05:00:00 UTC')));
    // Unknown model: no discount.
    $this->assertSame(1.0, $backend->getPriceMultiplier('some-other-model', strtotime('2026-08-17 05:00:00 UTC')));
  }

  /**
   * The status service decides "now", once per transition.
   */
  public function testStatusServiceAnswersForNow(): void {
    new Settings(['ai_provider_universal_deepseek_status_url' => 'https://status.test/v1/status']);
    $requests = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], (string) json_encode([
        'peak' => TRUE,
        'nextTransition' => ['at' => gmdate('c', time() + 3600), 'peak' => FALSE],
      ])),
    ]));
    $stack->push(Middleware::history($requests));
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('fromOptions')->willReturn(new Client(['handler' => $stack]));
    $stored = NULL;
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(function () use (&$stored) {
      return $stored;
    });
    $state->method('set')->willReturnCallback(function ($key, $value) use (&$stored) {
      $stored = $value;
    });
    $backend = new DeepSeek([], 'deepseek', [], $factory, $state);

    $this->assertSame(1.0, $backend->getPriceMultiplier('deepseek-flash'));
    // Cached until the transition: no second request.
    $this->assertSame(1.0, $backend->getPriceMultiplier('deepseek-flash'));
    $this->assertCount(1, $requests);
    // Any other moment is computed locally (Saturday: off-peak).
    $this->assertSame(0.5, $backend->getPriceMultiplier('deepseek-flash', strtotime('2026-08-22 02:00:00 UTC')));
    $this->assertCount(1, $requests);
  }

  /**
   * An unreachable service falls back to the schedule and is not retried.
   */
  public function testStatusServiceFailureFallsBack(): void {
    new Settings(['ai_provider_universal_deepseek_status_url' => 'https://status.test/v1/status']);
    $requests = [];
    $stack = HandlerStack::create(new MockHandler([new Response(500)]));
    $stack->push(Middleware::history($requests));
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('fromOptions')->willReturn(new Client(['handler' => $stack]));
    $stored = NULL;
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(function () use (&$stored) {
      return $stored;
    });
    $state->method('set')->willReturnCallback(function ($key, $value) use (&$stored) {
      $stored = $value;
    });
    $backend = new DeepSeek([], 'deepseek', [], $factory, $state);

    $local = $this->backend()->getPriceMultiplier('deepseek-flash');
    $this->assertSame($local, $backend->getPriceMultiplier('deepseek-flash'));
    $this->assertSame($local, $backend->getPriceMultiplier('deepseek-flash'));
    $this->assertCount(1, $requests);
  }

}
