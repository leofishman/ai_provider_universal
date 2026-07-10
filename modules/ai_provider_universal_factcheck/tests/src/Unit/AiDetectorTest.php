<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_provider_universal_factcheck\Service\AiDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests AiDetector score parsing and configuration gates.
 */
#[CoversClass(AiDetector::class)]
#[Group('ai_provider_universal')]
class AiDetectorTest extends UnitTestCase {

  /**
   * A detector whose model always answers with $modelResponse.
   */
  protected function buildDetector(string $modelResponse, array $settings = ['detector_model' => 'llama']): AiDetector {
    $detector = new class(
      // Final class, never reached: ask() is overridden below.
      (new \ReflectionClass(AiProviderPluginManager::class))->newInstanceWithoutConstructor(),
      $this->getConfigFactoryStub(['ai_provider_universal_factcheck.settings' => $settings]),
      $this->createMock(LoggerInterface::class),
    ) extends AiDetector {

      /**
       * The scripted model response.
       */
      public string $response = '';

      /**
       * Returns the scripted response instead of calling a real provider.
       */
      protected function ask(string $prompt, string $model): string {
        return $this->response;
      }

    };
    $detector->response = $modelResponse;
    return $detector;
  }

  /**
   * Without a configured model, detect() is unavailable.
   */
  public function testNoModelMeansUnavailable(): void {
    $detector = $this->buildDetector('irrelevant', []);
    $this->assertFalse($detector->isConfigured());
    $this->assertNull($detector->detect('Some text.'));
  }

  /**
   * Score and rationale are parsed from free-form model prose.
   */
  public function testParsesScoreAndRationaleFromProse(): void {
    $detector = $this->buildDetector('Sure! {"score": 72, "rationale": "Uniform rhythm."} Hope that helps.');
    $this->assertSame(['score' => 72, 'rationale' => 'Uniform rhythm.'], $detector->detect('Some text.'));
  }

  /**
   * Out-of-range scores are clamped to 0..100.
   */
  public function testScoreIsClampedTo0100(): void {
    $this->assertSame(100, $this->buildDetector('{"score": 250, "rationale": "r"}')->detect('t')['score']);
    $this->assertSame(0, $this->buildDetector('{"score": -3, "rationale": "r"}')->detect('t')['score']);
  }

  /**
   * Unparseable model output returns NULL rather than a partial result.
   */
  public function testUnparseableModelOutputReturnsNull(): void {
    $this->assertNull($this->buildDetector('It feels very human to me.')->detect('Some text.'));
    $this->assertNull($this->buildDetector('{"rationale": "no score key"}')->detect('Some text.'));
  }

}
