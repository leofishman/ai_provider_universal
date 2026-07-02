<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Utility;

use Drupal\ai_provider_universal\Utility\ModelFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests model filter glob matching.
 *
 * @coversDefaultClass \Drupal\ai_provider_universal\Utility\ModelFilter
 *
 * @group ai_provider_universal
 */
#[CoversClass(ModelFilter::class)]
#[Group('ai_provider_universal')]
final class ModelFilterTest extends UnitTestCase {

  /**
   * Tests model id glob matching.
   */
  #[DataProvider('providerMatches')]
  public function testMatches(string $model_id, string $pattern, bool $expected): void {
    $this->assertSame($expected, ModelFilter::matches($model_id, $pattern));
  }

  /**
   * Data provider for filter matching.
   */
  public static function providerMatches(): array {
    return [
      'empty pattern allows all' => ['llama3-8b', '', TRUE],
      'include glob match' => ['llama3-8b-instruct', 'llama3*', TRUE],
      'include glob no match' => ['mistral-7b', 'llama3*', FALSE],
      'multiple includes' => ['mistral-7b', 'llama3*, mistral*', TRUE],
      'exclude wins' => ['llama3-old', 'llama3*, !*old*', FALSE],
      'exclude only' => ['llama3-8b', '!*old*', TRUE],
      'case insensitive' => ['LLAMA3-8B', 'llama3*', TRUE],
    ];
  }

  /**
   * Tests the matchGlob helper with wildcards.
   */
  public function testMatchGlobWildcard(): void {
    $this->assertTrue(ModelFilter::matchGlob('foo-bar-baz', 'foo*baz'));
    $this->assertFalse(ModelFilter::matchGlob('foo-bar', 'foo*baz'));
  }

}
