<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Models\Moderation;

use Drupal\ai_provider_universal\Models\Moderation\ShieldGemma;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ShieldGemma prompt building and response parsing.
 *
 * @coversDefaultClass \Drupal\ai_provider_universal\Models\Moderation\ShieldGemma
 *
 * @group ai_provider_universal
 */
#[CoversClass(ShieldGemma::class)]
#[Group('ai_provider_universal')]
final class ShieldGemmaTest extends UnitTestCase {

  /**
   * @covers ::responseIndicatesViolation
   * @dataProvider providerResponses
   */
  #[DataProvider('providerResponses')]
  public function testResponseIndicatesViolation(string $response, bool $expected): void {
    $this->assertSame($expected, ShieldGemma::responseIndicatesViolation($response));
  }

  /**
   * Data provider for response parsing.
   */
  public static function providerResponses(): array {
    return [
      'plain yes' => ['Yes', TRUE],
      'plain no' => ['No', FALSE],
      'yes lowercase' => ['yes', TRUE],
      'yes with reasoning' => ["Yes, because the prompt contains a threat.", TRUE],
      'yes with leading whitespace' => ["  \nYes", TRUE],
      'no with reasoning' => ['No, this is a harmless question.', FALSE],
      'yesterday is not yes' => ['Yesterday was fine.', FALSE],
      'empty' => ['', FALSE],
    ];
  }

  /**
   * @covers ::getDefaultGuidelines
   */
  public function testGetDefaultGuidelines(): void {
    $guidelines = ShieldGemma::getDefaultGuidelines();
    $this->assertSame(
      ['harassment', 'hate_speech', 'dangerous_content', 'sexually_explicit'],
      array_keys($guidelines),
    );
    foreach ($guidelines as $guideline) {
      $this->assertNotEmpty($guideline);
    }
  }

  /**
   * @covers ::buildPrompt
   */
  public function testBuildPrompt(): void {
    $guideline = '"No Harassment": The prompt shall not contain abusive content.';
    $prompt = ShieldGemma::buildPrompt('I will hurt you', $guideline);

    // The content and guideline are interpolated into the template.
    $this->assertStringContainsString('Human Question: I will hurt you', $prompt);
    $this->assertStringContainsString('* ' . $guideline, $prompt);
    // The template control markers and answer instruction are present.
    $this->assertStringContainsString('<start_of_turn>', $prompt);
    $this->assertStringContainsString('<end_of_turn>', $prompt);
    $this->assertStringContainsString("must start with 'Yes' or 'No'", $prompt);
    // No leading <bos>; the completions endpoint prepends it.
    $this->assertStringStartsWith('You are a policy expert', $prompt);
  }

}
