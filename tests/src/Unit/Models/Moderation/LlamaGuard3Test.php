<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Models\Moderation;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\ai_provider_universal\Models\Moderation\LlamaGuard3;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests LlamaGuard3 "safe"/"unsafe\nSX" response parsing.
 *
 * @coversDefaultClass \Drupal\ai_provider_universal\Models\Moderation\LlamaGuard3
 *
 * @group ai_provider_universal
 */
#[CoversClass(LlamaGuard3::class)]
#[Group('ai_provider_universal')]
final class LlamaGuard3Test extends TestCase {

  protected function setUp(): void {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->createMock('\Drupal\Core\StringTranslation\TranslationInterface'));
    \Drupal::setContainer($container);
  }

  /**
   * Tests parsing of safe responses.
   */
  #[DataProvider('providerSafeResponses')]
  public function testSafeResponses(string $response): void {
    $result = LlamaGuard3::parse($response);
    $this->assertFalse((bool) $result->isFlagged());
  }

  /**
   * Data provider: responses that must NOT be flagged.
   */
  public static function providerSafeResponses(): array {
    return [
      'plain safe' => ['safe'],
      'safe with whitespace' => ["  safe\n"],
      'empty' => [''],
      'unrelated text' => ['I am happy to help.'],
    ];
  }

  /**
   * Tests parsing of unsafe responses and category extraction.
   */
  #[DataProvider('providerUnsafeResponses')]
  public function testUnsafeResponses(string $response, array $expectedReasons): void {
    $result = LlamaGuard3::parse($response);
    $this->assertTrue((bool) $result->isFlagged());
    $this->assertSame($expectedReasons, $result->getInformation());
  }

  /**
   * Data provider: unsafe responses and their decoded category labels.
   */
  public static function providerUnsafeResponses(): array {
    return [
      'single category' => ["unsafe\nS1", ['Violent Crimes']],
      'multiple newline-separated' => ["unsafe\nS1\nS11", ['Violent Crimes', 'Suicide & Self-Harm']],
      'multiple comma-separated' => ["unsafe\nS3,S10", ['Sex-Related Crimes', 'Hate']],
      'unknown code passthrough' => ["unsafe\nS99", ['S99']],
      'no code falls back' => ['unsafe', ['Unspecified']],
    ];
  }

}
