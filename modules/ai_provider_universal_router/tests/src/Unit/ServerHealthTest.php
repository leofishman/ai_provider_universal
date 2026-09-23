<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_router\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_router\Service\ServerHealth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests the breaker's growing marks.
 *
 * @group ai_provider_universal
 */
#[CoversClass(ServerHealth::class)]
#[Group('ai_provider_universal')]
final class ServerHealthTest extends UnitTestCase {

  /**
   * Tests that failures in a row double the mark and a quiet spell resets it.
   */
  public function testMarkDoublesOnRepeatedFailureAndResets(): void {
    $now = 1000;
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(function () use (&$now) {
      return $now;
    });
    $health = new ServerHealth(new MemoryBackend($time), $time, new NullLogger());

    // First failure: 60s.
    $health->markDown('laya');
    $now += 59;
    $this->assertTrue($health->isDown('laya'));
    $now += 1;
    $this->assertFalse($health->isDown('laya'));

    // Falls over again right after coming back: 120s.
    $health->markDown('laya');
    $now += 119;
    $this->assertTrue($health->isDown('laya'));
    $now += 1;
    $this->assertFalse($health->isDown('laya'));

    // Quiet for longer than the last mark: back to 60s.
    $now += 121;
    $health->markDown('laya');
    $now += 60;
    $this->assertFalse($health->isDown('laya'));
  }

}
