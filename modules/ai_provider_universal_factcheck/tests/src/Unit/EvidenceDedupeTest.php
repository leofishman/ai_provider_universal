<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Service\EvidenceRetriever;

/**
 * @coversDefaultClass \Drupal\ai_provider_universal_factcheck\Service\EvidenceRetriever
 * @group ai_provider_universal
 */
class EvidenceDedupeTest extends UnitTestCase {

  /**
   * Republished wire copy collapses to one passage; distinct writing stays.
   *
   * @covers ::dedupe
   */
  public function testDedupeCollapsesEchoesButKeepsIndependentSources(): void {
    $wire = 'The health ministry confirmed on Tuesday that the new screening '
      . 'program will begin in March across all regional hospitals and clinics.';
    $passages = [
      "[https://a.example/news] $wire",
      // Same wire copy republished with a trivial trailing edit.
      "[https://b.example/story] $wire It was widely shared.",
      // An independently written passage on the same topic.
      '[https://c.example/analysis] Analysts questioned whether the March '
        . 'rollout is realistic given staffing shortages reported last quarter.',
    ];

    $result = EvidenceRetriever::dedupe($passages);

    // The two echoes collapse to one; the independent source survives.
    $this->assertCount(2, $result);
    $this->assertSame($passages[0], $result[0]);
    $this->assertSame($passages[2], $result[1]);
  }

  /**
   * Non-overlapping passages are all kept.
   *
   * @covers ::dedupe
   */
  public function testDedupeKeepsAllDistinctPassages(): void {
    $passages = [
      '[https://a.example] Water boils at one hundred degrees at sea level.',
      '[https://b.example] The Eiffel Tower stands in the city of Paris.',
    ];
    $this->assertCount(2, EvidenceRetriever::dedupe($passages));
  }

}
