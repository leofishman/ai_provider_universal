<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Service\EvidenceRetriever;
use Drupal\ai_provider_universal_factcheck\Service\TrustedSiteRepository;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Web (Tavily) evidence path, with mocked HTTP.
 *
 * The local Search API path needs a booted container and is exercised in
 * kernel/manual testing; here search_api is reported missing so retrieve()
 * goes straight to the web cascade.
 *
 * @coversDefaultClass \Drupal\ai_provider_universal_factcheck\Service\EvidenceRetriever
 * @group ai_provider_universal
 */
class EvidenceRetrieverTest extends UnitTestCase {

  /**
   * Requests captured by the Guzzle history middleware.
   */
  protected array $history = [];

  /**
   * Builds a retriever with scripted HTTP responses.
   *
   * @param array $responses
   *   Guzzle responses/exceptions, in order.
   * @param string $tavilyKey
   *   The configured key id; '' disables web evidence.
   */
  protected function buildRetriever(array $responses, string $tavilyKey = 'tavily', array $include = [], array $exclude = []): EvidenceRetriever {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn (string $key) => $key === 'tavily_key' ? $tavilyKey : NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(FALSE);

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('sk-test');
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->willReturnCallback(
      static fn (string $id) => $id === 'tavily' ? $key : NULL,
    );

    $sites = $this->createMock(TrustedSiteRepository::class);
    $sites->method('includeDomains')->willReturn($include);
    $sites->method('excludeDomains')->willReturn($exclude);

    return new EvidenceRetriever(
      $this->createMock(EntityTypeManagerInterface::class),
      $configFactory,
      $moduleHandler,
      new Client(['handler' => $stack]),
      $keyRepository,
      $sites,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * JSON body of the captured request at $index.
   */
  protected function requestPayload(int $index): array {
    return json_decode((string) $this->history[$index]['request']->getBody(), TRUE);
  }

  /**
   * A Tavily response with the given url => content results.
   */
  protected function tavilyResponse(array $results): Response {
    $body = ['results' => []];
    foreach ($results as $url => $content) {
      $body['results'][] = ['url' => $url, 'content' => $content];
    }
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
  }

  /**
   * @covers ::retrieve
   */
  public function testNoKeyMeansNoWebEvidence(): void {
    $retriever = $this->buildRetriever([], tavilyKey: '');
    $this->assertSame([], $retriever->retrieve('Some claim.'));
    $this->assertCount(0, $this->history);
  }

  /**
   * @covers ::retrieve
   */
  public function testWebSearchAppliesCurationAndPrefixesSources(): void {
    $retriever = $this->buildRetriever(
      [$this->tavilyResponse(['https://good.example/a' => 'Relevant passage.'])],
      include: ['good.example'],
      exclude: ['bad.example'],
    );

    $passages = $retriever->retrieve('Some claim.', 2);

    $this->assertSame(['[https://good.example/a] Relevant passage.'], $passages);
    $payload = $this->requestPayload(0);
    $this->assertSame('Some claim.', $payload['query']);
    $this->assertSame(2, $payload['max_results']);
    $this->assertSame(['good.example'], $payload['include_domains']);
    $this->assertSame(['bad.example'], $payload['exclude_domains']);
    $this->assertSame('Bearer sk-test', $this->history[0]['request']->getHeaderLine('Authorization'));
  }

  /**
   * Empty curated search retries once without the include list.
   *
   * @covers ::retrieve
   */
  public function testEmptyCuratedSearchRetriesUnrestricted(): void {
    $retriever = $this->buildRetriever(
      [
        $this->tavilyResponse([]),
        $this->tavilyResponse(['https://other.example/b' => 'Found elsewhere.']),
      ],
      include: ['good.example'],
      exclude: ['bad.example'],
    );

    $passages = $retriever->retrieve('Niche claim.');

    $this->assertSame(['[https://other.example/b] Found elsewhere.'], $passages);
    $this->assertCount(2, $this->history);
    $retry = $this->requestPayload(1);
    $this->assertArrayNotHasKey('include_domains', $retry);
    $this->assertSame(['bad.example'], $retry['exclude_domains']);
  }

  /**
   * @covers ::retrieveDistrusted
   */
  public function testDistrustedSearchTargetsNegativeDomainsOnly(): void {
    $retriever = $this->buildRetriever(
      [$this->tavilyResponse(['https://bad.example/x' => 'The echoed claim.'])],
      include: ['good.example'],
      exclude: ['bad.example'],
    );

    $passages = $retriever->retrieveDistrusted('Some claim.');

    $this->assertSame(['[https://bad.example/x] The echoed claim.'], $passages);
    $payload = $this->requestPayload(0);
    $this->assertSame(['bad.example'], $payload['include_domains']);
    $this->assertArrayNotHasKey('exclude_domains', $payload);
  }

  /**
   * No distrusted domains configured: no search at all.
   *
   * @covers ::retrieveDistrusted
   */
  public function testDistrustedSearchSkippedWithoutNegativeDomains(): void {
    $retriever = $this->buildRetriever([], exclude: []);
    $this->assertSame([], $retriever->retrieveDistrusted('Some claim.'));
    $this->assertCount(0, $this->history);
  }

  /**
   * HTTP failure degrades to no evidence instead of throwing.
   *
   * @covers ::retrieve
   */
  public function testHttpFailureReturnsNoEvidence(): void {
    $retriever = $this->buildRetriever([
      new RequestException('boom', new Request('POST', 'https://api.tavily.com/search')),
    ]);
    $this->assertSame([], $retriever->retrieve('Some claim.'));
  }

}
