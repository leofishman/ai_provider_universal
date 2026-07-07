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

/**
 * MBFC API fetch path, with mocked HTTP.
 *
 * The fixture in tests/fixtures/mbfc-api-response-mini.json is 3 rows cut
 * from a real /ratings capture (the endpoint dumps the full ~15k dataset),
 * so this test validates our parsing against the real schema.
 *
 * @coversDefaultClass \Drupal\ai_provider_universal_factcheck\Service\BiasRatingImporter
 * @group ai_provider_universal
 */
class BiasRatingImporterTest extends UnitTestCase {

  /**
   * Requests captured by the Guzzle history middleware.
   */
  protected array $history = [];

  /**
   * Builds an importer with scripted HTTP responses.
   */
  protected function buildImporter(array $responses, string $mbfcKey = 'mbfc'): BiasRatingImporter {
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

    return new BiasRatingImporter(
      $this->createMock(EntityTypeManagerInterface::class),
      $configFactory,
      $keyRepository,
      new Client(['handler' => $stack]),
    );
  }

  /**
   * The fixture response parses into an importable site record.
   *
   * @covers ::fetchFromApi
   */
  public function testFetchParsesFixtureResponse(): void {
    $fixture = (string) file_get_contents(__DIR__ . '/../../fixtures/mbfc-api-response-mini.json');
    $importer = $this->buildImporter([new Response(200, [], $fixture)]);

    // Two domains, one of them stored in the dataset with a path suffix
    // ("metapedia.org/wiki/Main_Page") — one HTTP call covers both.
    $result = $importer->fetchFromApi(['canarymission.org', 'https://www.metapedia.org']);

    $this->assertSame([], $result['errors']);
    $this->assertCount(2, $result['sites']);
    [$canary, $metapedia] = $result['sites'];
    $this->assertSame('canarymission.org', $canary['domain']);
    $this->assertSame('Canary Mission', $canary['name']);
    // "Bias" is the unmappable "Questionable"; "Political Bias" wins.
    $this->assertSame('Right', $canary['bias']);
    $this->assertSame('Mixed', $canary['factual']);
    $this->assertSame('Low', $canary['credibility']);
    $this->assertSame('metapedia.org', $metapedia['domain']);
    $this->assertSame('Low', $metapedia['factual']);

    // Exactly one request, carrying the RapidAPI auth headers.
    $this->assertCount(1, $this->history);
    $request = $this->history[0]['request'];
    $this->assertSame('rapid-api-key', $request->getHeaderLine('X-RapidAPI-Key'));
  }

  /**
   * Missing key short-circuits without any HTTP call.
   *
   * @covers ::fetchFromApi
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
   *
   * @covers ::fetchFromApi
   */
  public function testFetchCollectsPerDomainErrors(): void {
    $fixture = (string) file_get_contents(__DIR__ . '/../../fixtures/mbfc-api-response-mini.json');
    $importer = $this->buildImporter([new Response(200, [], $fixture)]);

    $result = $importer->fetchFromApi(['down.example', 'xtramagazine.com']);

    $this->assertCount(1, $result['errors']);
    $this->assertStringContainsString('down.example', $result['errors'][0]);
    $this->assertCount(1, $result['sites']);
    $this->assertSame('xtramagazine.com', $result['sites'][0]['domain']);
    $this->assertSame('Left', $result['sites'][0]['bias']);
  }

  /**
   * An HTTP failure yields a single batch error and no sites.
   *
   * @covers ::fetchFromApi
   */
  public function testFetchReportsHttpFailure(): void {
    $importer = $this->buildImporter([new Response(500, [], 'boom')]);

    $result = $importer->fetchFromApi(['apnews.com']);

    $this->assertSame([], $result['sites']);
    $this->assertCount(1, $result['errors']);
  }

}
