<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Hook\AiProviderUniversalFactcheckHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests OOP hook_mail builder for factcheck notifications.
 */
#[CoversClass(AiProviderUniversalFactcheckHooks::class)]
#[Group('ai_provider_universal')]
final class FactcheckHooksTest extends UnitTestCase {

  /**
   * Subject and body from params land on the message array.
   */
  public function testMailBuildsMessage(): void {
    $hooks = new AiProviderUniversalFactcheckHooks();
    $message = ['body' => []];
    $hooks->mail('scan_run', $message, [
      'subject' => 'Scan done',
      'body' => 'Node X was scanned.',
    ]);

    $this->assertSame('Scan done', $message['subject']);
    $this->assertSame(['Node X was scanned.'], $message['body']);
  }

  /**
   * Missing params produce empty subject/body rather than notices.
   */
  public function testMailToleratesMissingParams(): void {
    $hooks = new AiProviderUniversalFactcheckHooks();
    $message = ['body' => []];
    $hooks->mail('settings_changed', $message, []);

    $this->assertSame('', $message['subject']);
    $this->assertSame([''], $message['body']);
  }

}
