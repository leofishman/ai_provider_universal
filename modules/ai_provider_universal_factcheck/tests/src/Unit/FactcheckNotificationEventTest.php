<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Event\FactcheckNotificationEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests FactcheckNotificationEvent accessors and mail suppress flag.
 */
#[CoversClass(FactcheckNotificationEvent::class)]
#[Group('ai_provider_universal')]
final class FactcheckNotificationEventTest extends UnitTestCase {

  /**
   * Accessors return constructor values; mail starts unsuppressed.
   */
  public function testAccessorsAndSuppressMail(): void {
    $event = new FactcheckNotificationEvent(
      FactcheckNotificationEvent::KEY_SCAN_RUN,
      'Title',
      'Body text',
      ['uid' => 5, 'scan_subject' => 'Node A'],
    );

    $this->assertSame(FactcheckNotificationEvent::KEY_SCAN_RUN, $event->getKey());
    $this->assertSame('Title', $event->getSubject());
    $this->assertSame('Body text', $event->getBody());
    $this->assertSame(['uid' => 5, 'scan_subject' => 'Node A'], $event->getContext());
    $this->assertFalse($event->isMailSuppressed());

    $event->suppressMail();
    $this->assertTrue($event->isMailSuppressed());
  }

}
