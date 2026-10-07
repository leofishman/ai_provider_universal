<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_terms\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_provider_universal_terms\Service\TermsAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests TermsAnalyzer parsing, quote checking, chunking and caching.
 */
#[CoversClass(TermsAnalyzer::class)]
#[Group('ai_provider_universal')]
class TermsAnalyzerTest extends UnitTestCase {

  protected const DOC = "Estos términos se rigen por las leyes del Estado de California.\n"
    . "Podemos cancelar tu cuenta en cualquier momento y sin aviso previo.\n"
    . "Compartimos tus datos con empresas de nuestro grupo.";

  /**
   * Shared cache store.
   */
  protected array $cacheStore = [];

  /**
   * An analyzer whose model replies are scripted; NULL replies are failures.
   */
  protected function buildAnalyzer(array $replies, array $settings = []): TermsAnalyzer {
    $settings += ['model' => 'qwen', 'chunk_words' => 0];
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn (string $key) => $settings[$key] ?? NULL);
    $config->method('getRawData')->willReturn($settings);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(fn (string $cid) => $this->cacheStore[$cid] ?? FALSE);
    $cache->method('set')->willReturnCallback(function (string $cid, $data): void {
      $this->cacheStore[$cid] = (object) ['data' => $data];
    });

    $analyzer = new class(
      // Final class, never reached: ask() is overridden below.
      (new \ReflectionClass(AiProviderPluginManager::class))->newInstanceWithoutConstructor(),
      $configFactory,
      $cache,
      $this->createMock(LoggerInterface::class),
    ) extends TermsAnalyzer {

      /**
       * Scripted replies, shifted per ask() call.
       */
      public array $replies = [];

      /**
       * Number of model calls made.
       */
      public int $calls = 0;

      /**
       * Returns the next scripted reply.
       */
      protected function ask(string $piece): ?string {
        $this->calls++;
        return array_shift($this->replies);
      }

    };
    $analyzer->replies = $replies;
    return $analyzer;
  }

  /**
   * Real quotes are kept; invented ones, and unknown categories, are not.
   */
  public function testInventedQuotesAreDropped(): void {
    $reply = "Acá va:\n```json\n" . json_encode([
      'choice_of_law' => ['se rigen por las leyes del Estado de California'],
      'unilateral_termination' => [
        'Podemos   CANCELAR tu cuenta en cualquier momento',
        'una cita que no está en el texto',
      ],
      'data_sharing' => ['Vendemos tus datos a quien pague'],
      'not_a_category' => ['Compartimos tus datos con empresas'],
    ]) . "\n```";
    $result = $this->buildAnalyzer([$reply])->analyze(self::DOC);

    $this->assertSame(['choice_of_law', 'unilateral_termination'], array_keys($result['findings']));
    $this->assertSame(['Podemos   CANCELAR tu cuenta en cualquier momento'], $result['findings']['unilateral_termination']['quotes']);
    $this->assertSame(2, $result['dropped_quotes']);
    $this->assertSame(hash('sha256', TermsAnalyzer::normalize(self::DOC)), $result['hash']);
  }

  /**
   * Pieces are analyzed separately and their findings merged.
   */
  public function testChunksAreMerged(): void {
    $analyzer = $this->buildAnalyzer([
      '{"choice_of_law": ["se rigen por las leyes del Estado de California"]}',
      NULL,
      '{"data_sharing": ["Compartimos tus datos con empresas de nuestro grupo"]}',
    ], ['chunk_words' => 12]);
    $result = $analyzer->analyze(self::DOC);

    $this->assertSame(3, $analyzer->calls);
    $this->assertSame(['choice_of_law', 'data_sharing'], array_keys($result['findings']));
    $this->assertSame(1, $result['failed_pieces']);
  }

  /**
   * A complete result is cached by text; a partial one is not.
   */
  public function testCaching(): void {
    $partial = $this->buildAnalyzer(['{}', NULL], ['chunk_words' => 12]);
    $partial->analyze(self::DOC);
    $this->assertSame([], $this->cacheStore);

    $first = $this->buildAnalyzer(['{"choice_of_law": ["se rigen por las leyes del Estado de California"]}']);
    $first->analyze(self::DOC);
    $second = $this->buildAnalyzer([]);
    // Same document with other whitespace: same hash, no model call.
    $result = $second->analyze(str_replace(' ', "  ", self::DOC));
    $this->assertSame(0, $second->calls);
    $this->assertArrayHasKey('choice_of_law', $result['findings']);
  }

  /**
   * No usable answer at all is an error, not an empty report.
   */
  public function testNoUsableAnswerThrows(): void {
    $this->expectException(\RuntimeException::class);
    $this->buildAnalyzer(['no sé', '["a list, not an object"]'], ['chunk_words' => 20])->analyze(self::DOC);
  }

  /**
   * Chunks cut between paragraphs and keep every paragraph.
   */
  public function testChunks(): void {
    $this->assertSame([self::DOC], TermsAnalyzer::chunks(self::DOC, 0));
    $chunks = TermsAnalyzer::chunks(self::DOC, 12);
    $this->assertCount(3, $chunks);
    $this->assertSame(self::DOC, implode("\n", $chunks));
  }

}
