<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_governance\Unit\Utility;

use Drupal\ai_provider_universal_governance\Utility\InternalChatTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests internal chat tag detection.
 */
#[CoversClass(InternalChatTags::class)]
#[Group('ai_provider_universal')]
final class InternalChatTagsTest extends TestCase {

  /**
   * Known tool tags are internal; empty and unrelated tags are not.
   */
  public function testIsInternal(): void {
    $this->assertFalse(InternalChatTags::isInternal([]));
    $this->assertFalse(InternalChatTags::isInternal(['chat', 'my_module']));
    $this->assertTrue(InternalChatTags::isInternal(['chat', InternalChatTags::FACTCHECK]));
    $this->assertTrue(InternalChatTags::isInternal([InternalChatTags::COMPLEXITY_CLASSIFIER]));
    $this->assertTrue(InternalChatTags::isInternal([InternalChatTags::ROUTE_VERIFIER]));
  }

}
