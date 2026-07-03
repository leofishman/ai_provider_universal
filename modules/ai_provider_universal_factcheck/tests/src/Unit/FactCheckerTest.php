<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_provider_universal_factcheck\Service\EvidenceRetriever;
use Drupal\ai_provider_universal_factcheck\Service\FactChecker;
use Drupal\ai_provider_universal_factcheck\Service\TrustedSiteRepository;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\ai_provider_universal_factcheck\Service\FactChecker
 * @group ai_provider_universal
 */
class FactCheckerTest extends UnitTestCase {

  /**
   * Prompts sent to the (scripted) model, in order.
   */
  protected array $prompts = [];

  /**
   * Shared cache store, so two checkers in one test see the same cache.
   */
  protected array $cacheStore = [];

  protected function setUp(): void {
    parent::setUp();
    $this->prompts = [];
    $this->cacheStore = [];
  }

  /**
   * A FactChecker whose model calls are scripted, not real.
   *
   * @param array $settings
   *   Module settings ('checker_model', 'profile', ...).
   * @param array $responses
   *   Model responses returned in order; '' once exhausted.
   */
  protected function buildChecker(array $settings, array $responses, ?EvidenceRetriever $evidence = NULL, ?TrustedSiteRepository $sites = NULL): FactChecker {
    $settings += ['checker_model' => 'llama', 'max_claims' => 5];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn (string $key) => $settings[$key] ?? NULL);
    $config->method('getRawData')->willReturn($settings);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(function (string $cid) {
      return $this->cacheStore[$cid] ?? FALSE;
    });
    $cache->method('set')->willReturnCallback(function (string $cid, $data): void {
      $this->cacheStore[$cid] = (object) ['data' => $data];
    });

    $test = $this;
    $checker = new class(
      // Final class, never reached: ask() is overridden below.
      (new \ReflectionClass(AiProviderPluginManager::class))->newInstanceWithoutConstructor(),
      $configFactory,
      $evidence ?? $this->createMock(EvidenceRetriever::class),
      $sites ?? $this->createMock(TrustedSiteRepository::class),
      $cache,
      $this->createMock(LoggerInterface::class),
    ) extends FactChecker {

      /**
       * Scripted model responses, shifted per ask() call.
       */
      public array $responses = [];

      /**
       * The test case, to record prompts.
       */
      public FactCheckerTest $test;

      protected function ask(string $prompt, string $model): string {
        $this->test->recordPrompt($prompt);
        return array_shift($this->responses) ?? '';
      }

    };
    $checker->responses = $responses;
    $checker->test = $test;
    return $checker;
  }

  /**
   * Callback for the scripted subclass.
   */
  public function recordPrompt(string $prompt): void {
    $this->prompts[] = $prompt;
  }

  /**
   * @covers ::verify
   * @covers ::extractClaims
   */
  public function testNoExtractableClaimsScoresPerfect(): void {
    $checker = $this->buildChecker(['profile' => 'balanced'], ['I have no idea what you mean.']);
    $result = $checker->verify('Q', 'Some vague answer.');
    $this->assertSame(1.0, $result['score']);
    $this->assertSame([], $result['claims']);
    $this->assertCount(1, $this->prompts);
  }

  /**
   * @covers ::extractClaims
   */
  public function testExtractClaimsParsesFencedJsonAndRespectsBudget(): void {
    $checker = $this->buildChecker(
      ['profile' => 'balanced', 'max_claims' => 2],
      ["Here you go:\n```json\n[\"A\", \"B\", \"C\"]\n```"],
    );
    $this->assertSame(['A', 'B'], $checker->extractClaims('text'));
  }

  /**
   * Balanced profile: one batched verdict call, one answer-level taint call.
   *
   * @covers ::verify
   * @covers ::batchVerify
   * @covers ::taintedForAnswer
   */
  public function testBalancedProfileBatchesVerdictsAndTaint(): void {
    $evidence = $this->createMock(EvidenceRetriever::class);
    $evidence->method('retrieve')->willReturn(['[https://good.example/a] Passage.']);
    $evidence->method('retrieveDistrusted')->willReturn(['[https://bad.example/x] Echoed claim.']);

    $checker = $this->buildChecker(['profile' => 'balanced'], [
      '["Claim A", "Claim B"]',
      '[{"id": 1, "verdict": "SUPPORTED"}, {"id": 2, "verdict": "SUPPORTED"}]',
      '[2]',
    ], $evidence);

    $result = $checker->verify('Q', 'answer');

    // 3 calls total: extract + batch verdict + batch taint.
    $this->assertCount(3, $this->prompts);
    $this->assertStringContainsString('CLAIM 1: Claim A', $this->prompts[1]);
    $this->assertStringContainsString('CLAIM 2: Claim B', $this->prompts[1]);
    $this->assertSame('SUPPORTED', $result['claims'][0]['verdict']);
    $this->assertFalse($result['claims'][0]['tainted']);
    $this->assertTrue($result['claims'][1]['tainted']);
    // (2 supported - 0.5 * 1 tainted) / 2 claims.
    $this->assertSame(0.75, $result['score']);
  }

  /**
   * Unparseable batch output falls back to per-claim verification.
   *
   * @covers ::verify
   * @covers ::batchVerify
   */
  public function testBatchFailureFallsBackToPerClaim(): void {
    $evidence = $this->createMock(EvidenceRetriever::class);
    $evidence->method('retrieve')->willReturn([]);
    $evidence->method('retrieveDistrusted')->willReturn([]);

    $checker = $this->buildChecker(['profile' => 'balanced'], [
      '["Claim A", "Claim B"]',
      'Sorry, I cannot produce JSON today.',
      'SUPPORTED',
      'CONTRADICTED',
    ], $evidence);

    $result = $checker->verify('Q', 'answer');

    // Extract + failed batch + 2 per-claim calls.
    $this->assertCount(4, $this->prompts);
    $this->assertSame('SUPPORTED', $result['claims'][0]['verdict']);
    $this->assertSame('CONTRADICTED', $result['claims'][1]['verdict']);
    $this->assertSame(0.5, $result['score']);
  }

  /**
   * Fast profile skips distrusted checks and discrepancy analysis.
   *
   * @covers ::verify
   */
  public function testFastProfileSkipsDistrustedAndAnalysis(): void {
    $evidence = $this->createMock(EvidenceRetriever::class);
    $evidence->method('retrieve')->willReturn([
      '[https://a.example] One.',
      '[https://b.example] Two.',
    ]);
    $evidence->expects($this->never())->method('retrieveDistrusted');

    $checker = $this->buildChecker(['profile' => 'fast'], [
      '["Claim A"]',
      '[{"id": 1, "verdict": "CONTRADICTED"}]',
    ], $evidence);

    $result = $checker->verify('Q', 'answer');

    $this->assertCount(2, $this->prompts);
    $this->assertSame('CONTRADICTED', $result['claims'][0]['verdict']);
    // Two sources and an unsettled verdict, but fast never analyzes.
    $this->assertSame('', $result['claims'][0]['analysis']);
    $this->assertFalse($result['claims'][0]['tainted']);
  }

  /**
   * Thorough profile: per-claim verdicts, per-claim taint, analysis.
   *
   * @covers ::verify
   * @covers ::analyzeDiscrepancy
   */
  public function testThoroughProfileAnalyzesDiscrepancies(): void {
    $evidence = $this->createMock(EvidenceRetriever::class);
    $evidence->method('retrieve')->willReturn([
      '[https://a.example/1] The launch happened in March.',
      '[https://b.example/2] The launch was postponed to June.',
    ]);
    $evidence->method('retrieveDistrusted')->willReturn(['[https://bad.example] Echo.']);

    $neutral = ['reputation' => 0, 'bias' => '', 'owner' => '', 'assessments' => []];
    $sites = $this->createMock(TrustedSiteRepository::class);
    $sites->method('profile')->willReturnMap([
      ['a.example', ['reputation' => 8, 'bias' => '', 'owner' => '', 'assessments' => []]],
      ['b.example', ['reputation' => 6, 'bias' => '', 'owner' => '', 'assessments' => ['MBFC: mixed factual record']]],
      ['bad.example', $neutral],
    ]);

    $checker = $this->buildChecker(['profile' => 'thorough'], [
      '["Claim A"]',
      'CONTRADICTED',
      'SUPPORTED',
      'Source a.example says March; source b.example says June. a.example (+8) is better supported.',
    ], $evidence, $sites);

    $result = $checker->verify('Q', 'answer');

    // Extract + per-claim verdict + per-claim taint + analysis.
    $this->assertCount(4, $this->prompts);
    $this->assertStringContainsString('(reputation +8)', $this->prompts[3]);
    $this->assertStringContainsString('(reputation +6; watchdog notes: MBFC: mixed factual record)', $this->prompts[3]);
    $this->assertTrue($result['claims'][0]['tainted']);
    $this->assertStringContainsString('better supported', $result['claims'][0]['analysis']);
    // 0 supported - 0.5 tainted, clamped at 0.
    $this->assertSame(0.0, $result['score']);
  }

  /**
   * Cached verdicts skip everything but extraction on a re-scan.
   *
   * @covers ::verify
   */
  public function testVerdictsAreCachedAcrossScans(): void {
    $evidence = $this->createMock(EvidenceRetriever::class);
    $evidence->method('retrieve')->willReturn([]);
    $evidence->method('retrieveDistrusted')->willReturn([]);

    $responses = ['["Claim A"]', '[{"id": 1, "verdict": "SUPPORTED"}]'];
    $checker = $this->buildChecker(['profile' => 'balanced'], $responses, $evidence);
    $first = $checker->verify('Q', 'answer');

    // Same settings, same (shared) cache: only the extract call runs.
    $second = $this->buildChecker(['profile' => 'balanced'], ['["Claim A"]'], $evidence)
      ->verify('Q', 'answer');

    $this->assertCount(3, $this->prompts);
    $this->assertSame($first['claims'], $second['claims']);
    $this->assertSame(1.0, $second['score']);
  }

  /**
   * MiniCheck checkers use the Document/Claim interface, never batching.
   *
   * @covers ::verify
   * @covers ::verifyClaim
   */
  public function testMiniCheckUsesGroundedInterfacePerClaim(): void {
    $evidence = $this->createMock(EvidenceRetriever::class);
    $evidence->method('retrieve')->willReturn(['[https://a.example] Grounding passage.']);
    $evidence->method('retrieveDistrusted')->willReturn([]);

    $checker = $this->buildChecker([
      'profile' => 'balanced',
      'checker_model' => 'bespoke-minicheck-7b',
      'extractor_model' => 'llama',
    ], [
      '["Claim A"]',
      'Yes',
    ], $evidence);

    $result = $checker->verify('Q', 'answer');

    $this->assertCount(2, $this->prompts);
    $this->assertStringStartsWith('Document:', $this->prompts[1]);
    $this->assertSame('SUPPORTED', $result['claims'][0]['verdict']);
    $this->assertSame(1.0, $result['score']);
  }

  /**
   * Coverage summarizes source count, ownership independence and blindspot.
   *
   * @covers ::coverage
   */
  public function testCoverageSummarizesIndependenceAndBlindspot(): void {
    $passages = [
      '[https://a.example/1] One.',
      '[https://b.example/2] Two.',
      '[https://c.example/3] Three.',
      'No source prefix here.',
    ];
    $profiles = [
      'a.example' => ['reputation' => 8, 'bias' => 'lean_left', 'owner' => 'Acme Media', 'assessments' => []],
      'b.example' => ['reputation' => 6, 'bias' => 'left', 'owner' => 'Acme Media', 'assessments' => []],
      // c.example is uncurated: counts as its own owner, no bias.
    ];

    $coverage = FactChecker::coverage($passages, $profiles);

    $this->assertSame(3, $coverage['sources']);
    // a + b share an owner; c stands alone.
    $this->assertSame(2, $coverage['independent']);
    $this->assertSame(['lean_left' => 1, 'left' => 1], $coverage['biases']);
    $this->assertSame('left', $coverage['blindspot']);

    // No URL-prefixed passages: nothing to describe.
    $this->assertSame([], FactChecker::coverage(['plain text'], []));
  }

  /**
   * Leaning sources on both sides of the spectrum: no blindspot.
   *
   * @covers ::coverage
   */
  public function testCoverageWithMixedBiasHasNoBlindspot(): void {
    $profiles = [
      'a.example' => ['reputation' => 5, 'bias' => 'lean_left', 'owner' => '', 'assessments' => []],
      'b.example' => ['reputation' => 5, 'bias' => 'lean_right', 'owner' => '', 'assessments' => []],
    ];

    $coverage = FactChecker::coverage(
      ['[https://a.example/x] one take', '[https://b.example/y] another take'],
      $profiles,
    );

    $this->assertSame('', $coverage['blindspot']);
  }

}
