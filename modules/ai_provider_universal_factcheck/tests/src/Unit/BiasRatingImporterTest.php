<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Service\BiasRatingImporter;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * MBFC API fetch path, with mocked HTTP.
 *
 * The fixture in tests/fixtures/mbfc-api-response-mini.json has the schema
 * of a real /fetch-data capture (the endpoint dumps the full ~15k dataset)
 * with invented rows, so this test validates our parsing against the real
 * schema without shipping MBFC's data.
 */
#[CoversClass(BiasRatingImporter::class)]
#[Group('ai_provider_universal')]
class BiasRatingImporterTest extends UnitTestCase {

  /**
   * Requests captured by the Guzzle history middleware.
   */
  protected array $history = [];

  /**
   * Builds an importer with scripted HTTP responses.
   */
  protected function buildImporter(array $responses, string $mbfcKey = 'mbfc', string $class = BiasRatingImporter::class): BiasRatingImporter {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn (string $key) => $key === 'mbfc_key' ? $mbfcKey : NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('rapid-api-key');
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->willReturnCallback(static fn (string $id) => $id === 'mbfc' ? $key : NULL);

    return new $class(
      $this->createMock(EntityTypeManagerInterface::class),
      $configFactory,
      $keyRepository,
      new Client(['handler' => $stack]),
    );
  }

  /**
   * The fixture response parses into an importable site record.
   */
  public function testFetchParsesFixtureResponse(): void {
    $fixture = (string) file_get_contents(__DIR__ . '/../../fixtures/mbfc-api-response-mini.json');
    $importer = $this->buildImporter([new Response(200, [], $fixture)]);

    // Two domains, one of them stored in the dataset with a path suffix
    // ("beta-wiki.example/wiki/Main_Page") — one HTTP call covers both.
    $result = $importer->fetchFromApi(['alpha-watch.example', 'https://www.beta-wiki.example']);

    $this->assertSame([], $result['errors']);
    $this->assertCount(2, $result['sites']);
    [$alpha, $beta] = $result['sites'];
    $this->assertSame('alpha-watch.example', $alpha['domain']);
    $this->assertSame('Alpha Watch', $alpha['name']);
    // "Bias" is the unmappable "Questionable"; "Political Bias" wins.
    $this->assertSame('Right', $alpha['bias']);
    $this->assertSame('Mixed', $alpha['factual']);
    $this->assertSame('Low', $alpha['credibility']);
    $this->assertSame('beta-wiki.example', $beta['domain']);
    $this->assertSame('Low', $beta['factual']);

    // Exactly one request, carrying the RapidAPI auth headers.
    $this->assertCount(1, $this->history);
    $request = $this->history[0]['request'];
    $this->assertSame('rapid-api-key', $request->getHeaderLine('X-RapidAPI-Key'));
    $this->assertSame('/fetch-data', $request->getUri()->getPath());
  }

  /**
   * Missing key short-circuits without any HTTP call.
   */
  public function testFetchWithoutKeyMakesNoRequest(): void {
    $importer = $this->buildImporter([], '');

    $result = $importer->fetchFromApi(['foxnews.com']);

    $this->assertSame([], $result['sites']);
    $this->assertNotEmpty($result['errors']);
    $this->assertCount(0, $this->history);
  }

  /**
   * A domain missing from the dataset is reported but does not abort.
   */
  public function testFetchCollectsPerDomainErrors(): void {
    $fixture = (string) file_get_contents(__DIR__ . '/../../fixtures/mbfc-api-response-mini.json');
    $importer = $this->buildImporter([new Response(200, [], $fixture)]);

    $result = $importer->fetchFromApi(['down.example', 'gamma-magazine.example']);

    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('down.example', $result['errors'][0]);
    $this->assertCount(1, $result['sites']);
    $this->assertSame('gamma-magazine.example', $result['sites'][0]['domain']);
    $this->assertSame('Left', $result['sites'][0]['bias']);
  }

  /**
   * An HTTP failure yields a single batch error and no sites.
   */
  public function testFetchReportsHttpFailure(): void {
    $importer = $this->buildImporter([new Response(500, [], 'boom')]);

    $result = $importer->fetchFromApi(['apnews.com']);

    $this->assertSame([], $result['sites']);
    $this->assertCount(1, $result['errors']);
  }

  /**
   * Lin et al. rows map pc1 onto reputation, with a citing assessment.
   */
  public function testLin2023ParsesPinnedFile(): void {
    $importer = $this->buildImporter([new Response(200, [], Lin2023TestImporter::CSV)], class: Lin2023TestImporter::class);

    $result = $importer->fetchFromLin2023(['alpha-watch.example', 'https://www.gamma-magazine.example/', 'down.example']);

    $this->assertSame(['down.example: not found in Lin et al. 2023.'], $result['errors']);
    $this->assertSame(['alpha-watch.example', 'gamma-magazine.example'], array_column($result['sites'], 'domain'));
    $this->assertSame([-9, 10], array_column($result['sites'], 'reputation'));
    $this->assertStringContainsString('pgad286', $result['sites'][0]['source']);
    $this->assertArrayNotHasKey('bias', $result['sites'][0]);
    $this->assertStringContainsString('hauselin/domain-quality-ratings/5671d57', (string) $this->history[0]['request']->getUri());
  }

  /**
   * A file that no longer matches the pinned checksum is refused.
   */
  public function testLin2023RefusesChangedFile(): void {
    $importer = $this->buildImporter([new Response(200, [], Lin2023TestImporter::CSV . "evil.example,1\r\n")], class: Lin2023TestImporter::class);

    $result = $importer->fetchFromLin2023(['alpha-watch.example']);

    $this->assertSame([], $result['sites']);
    $this->assertStringContainsString('checksum', $result['errors'][0]);
  }

  /**
   * The pc1 mapping is linear and clamped.
   */
  public function testPc1ToReputation(): void {
    $importer = $this->buildImporter([]);
    $this->assertSame(-10, $importer->pc1ToReputation(0.0));
    $this->assertSame(0, $importer->pc1ToReputation(0.5));
    $this->assertSame(7, $importer->pc1ToReputation(0.86));
    $this->assertSame(10, $importer->pc1ToReputation(1.2));
  }

}

/**
 * Importer pinned to an invented CSV instead of the real file.
 */
class Lin2023TestImporter extends BiasRatingImporter {

  /**
   * Invented rows in the format of domain_pc1.csv.
   */
  public const CSV = "domain,pc1\r\nalpha-watch.example,0.05\r\nbeta-wiki.example,0.8\r\nwww.gamma-magazine.example,1\r\n";

  /**
   * SHA-256 of CSV.
   */
  public const LIN2023_SHA256 = 'dde6703ea8eb4f3f92ab1ea19dea736af90671a8def440621752f44c695c68ac';

}
