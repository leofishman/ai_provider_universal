<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Utility;

use Drupal\ai_provider_universal\Utility\PromptPlaceholders;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests placeholder-sequence validation for prompt overrides.
 */
#[CoversClass(PromptPlaceholders::class)]
#[Group('ai_provider_universal')]
final class PromptPlaceholdersTest extends UnitTestCase {

  /**
   * Overrides must keep the exact placeholder sequence of the default.
   */
  public function testMatches(): void {
    $default = "Extract up to %d claims from:\n%s";

    $this->assertTrue(PromptPlaceholders::matches($default, "Dame %d afirmaciones del TEXTO:\n%s"));
    // Reordered, missing, extra or wrong-typed placeholders all fail.
    $this->assertFalse(PromptPlaceholders::matches($default, "Claims (%s) up to %d"));
    $this->assertFalse(PromptPlaceholders::matches($default, 'No placeholders at all'));
    $this->assertFalse(PromptPlaceholders::matches($default, "%d %s and a stray %s"));
    $this->assertFalse(PromptPlaceholders::matches($default, "%s %s"));
    // Placeholder-free templates accept any wording.
    $this->assertTrue(PromptPlaceholders::matches('Reply yes or no.', 'Responde sí o no.'));
  }

  /**
   * Form descriptions list the expected placeholders in order.
   */
  public function testDescribe(): void {
    $this->assertSame('%d, %s', PromptPlaceholders::describe("Up to %d from:\n%s"));
    $this->assertSame('', PromptPlaceholders::describe('None here.'));
  }

}
