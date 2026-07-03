<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Service\ReadabilityScorer;

/**
 * @coversDefaultClass \Drupal\ai_provider_universal_factcheck\Service\ReadabilityScorer
 * @group ai_provider_universal
 */
class ReadabilityScorerTest extends UnitTestCase {

  /**
   * @covers ::score
   */
  public function testShortTextReturnsNull(): void {
    $this->assertNull((new ReadabilityScorer())->score('Too short.'));
  }

  /**
   * @covers ::score
   */
  public function testSimpleTextScoresEasierThanDenseText(): void {
    $scorer = new ReadabilityScorer();

    $simple = $scorer->score('The cat sat on the mat. The dog ran to the park. We like to play with a ball. It was a good day for all of us.');
    $dense = $scorer->score('Notwithstanding institutional heterogeneity, interdisciplinary organizational infrastructures systematically operationalize multidimensional epistemological frameworks, thereby facilitating comprehensive administrative rationalization across heterogeneous bureaucratic configurations.');

    $this->assertNotNull($simple);
    $this->assertNotNull($dense);
    $this->assertGreaterThan($dense['score'], $simple['score']);
    $this->assertSame('very difficult', $dense['band']);
    $this->assertGreaterThanOrEqual(0.0, $dense['score']);
    $this->assertLessThanOrEqual(100.0, $simple['score']);
    $this->assertSame(4, $simple['sentences']);
  }

}
