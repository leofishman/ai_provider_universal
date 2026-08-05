<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Utility;

use Drupal\ai_provider_universal\Utility\ModelDefaults;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests default quality tier guessing for known model families.
 *
 * @group ai_provider_universal
 */
#[CoversClass(ModelDefaults::class)]
#[Group('ai_provider_universal')]
final class ModelDefaultsTest extends TestCase {

  /**
   * Tests tier guessing from raw model ids.
   */
  #[DataProvider('providerGuessTier')]
  public function testGuessTier(string $raw_id, ?int $expected): void {
    $this->assertSame($expected, ModelDefaults::guessTier($raw_id));
  }

  /**
   * Data provider: raw model id => expected tier.
   */
  public static function providerGuessTier(): array {
    return [
      // Named frontier/strong families.
      ['anthropic/claude-opus-4-8', 5],
      ['claude-sonnet-5', 4],
      ['openai/gpt-5-turbo', 5],
      ['gpt-4o-mini', 4],
      ['deepseek-r1', 5],
      ['deepseek-v3', 4],
      ['gemini-2.5-pro', 5],
      ['gemini-2.0-flash', 3],
      ['mistral-large-latest', 4],
      ['mixtral-8x22b', 3],
      ['claude-haiku-4-5', 3],
      // Parameter-count heuristic.
      ['accounts/fireworks/models/llama-v3p1-405b-instruct', 4],
      ['llama3:70b', 3],
      ['Qwen/Qwen2.5-7B-Instruct', 2],
      ['llama3.2:1b', 1],
      ['qwen2.5:0.5b', 1],
      ['smollm2', 1],
      // Unknown: stays unset for manual entry.
      ['bespoke-minicheck', NULL],
      ['nomic-embed-text', NULL],
    ];
  }

  /**
   * Tests sampling guessing from the shipped vendor-recommendation table.
   */
  public function testGuessSampling(): void {
    $this->assertSame(['temperature' => 0.6, 'top_p' => 0.95], ModelDefaults::guessSampling('qwen/qwen3-32b'));
    $this->assertSame(['temperature' => 0.6, 'top_p' => 0.95], ModelDefaults::guessSampling('deepseek-r1:8b'));
    $this->assertSame(['temperature' => 1.0, 'top_p' => 1.0], ModelDefaults::guessSampling('openai/gpt-oss-120b'));
    // No vendor recommendation: stay on the server default.
    $this->assertSame([], ModelDefaults::guessSampling('llama-3.3-70b-versatile'));
  }

  /**
   * Tests the price-band tier fallback for live-pricing catalogs.
   */
  public function testGuessTierFromPrice(): void {
    $this->assertSame(5, ModelDefaults::guessTierFromPrice(75.0));
    $this->assertSame(4, ModelDefaults::guessTierFromPrice(10.0));
    $this->assertSame(3, ModelDefaults::guessTierFromPrice(0.79));
    $this->assertSame(2, ModelDefaults::guessTierFromPrice(0.3));
    $this->assertSame(1, ModelDefaults::guessTierFromPrice(0.0));
  }

  /**
   * Tests cost guessing (shipped table is empty; seed one via reflection).
   */
  public function testGuessCosts(): void {
    // The module ships no prices on purpose: unknown ids yield no costs.
    $this->assertSame([], ModelDefaults::guessCosts('gpt-4o-mini'));

    $table = new \ReflectionProperty(ModelDefaults::class, 'table');
    $table->setValue(NULL, [
      'costs' => [
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.6],
        'my-local-.*' => ['input' => 0],
      ],
    ]);
    try {
      $this->assertSame(['cost_input' => 0.15, 'cost_output' => 0.6], ModelDefaults::guessCosts('openai/gpt-4o-mini'));
      // Partial entries fill only what they define; 0 is a valid price.
      $this->assertSame(['cost_input' => 0], ModelDefaults::guessCosts('my-local-llama'));
      $this->assertSame([], ModelDefaults::guessCosts('unknown-model'));
    }
    finally {
      $table->setValue(NULL, NULL);
    }
  }

}
