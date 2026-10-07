<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Exception\PageFetchException;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests PageFetcher's SSRF guard and text extraction.
 */
#[CoversClass(PageFetcher::class)]
#[Group('ai_provider_universal')]
class PageFetcherTest extends UnitTestCase {

  /**
   * Builds a fetcher whose HTTP client answers with the given responses.
   */
  private function fetcher(array $responses): PageFetcher {
    $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    return new PageFetcher($client);
  }

  /**
   * Non-http(s) and internal addresses are refused before any request.
   */
  #[DataProvider('blockedUrls')]
  public function testBlockedUrlsAreRefused(string $url): void {
    $this->expectException(PageFetchException::class);
    $this->fetcher([])->fetch($url);
  }

  /**
   * URLs the guard must refuse.
   */
  public static function blockedUrls(): array {
    return [
      'file scheme' => ['file:///etc/passwd'],
      'loopback' => ['http://127.0.0.1/'],
      'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
      'private range' => ['http://192.168.1.150:8095/'],
      'ipv6 loopback' => ['http://[::1]/'],
    ];
  }

  /**
   * A public URL redirecting to an internal address is refused.
   */
  public function testRedirectToInternalAddressIsRefused(): void {
    $fetcher = $this->fetcher([
      new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
      new Response(200, [], 'secret'),
    ]);
    $this->expectException(PageFetchException::class);
    $fetcher->fetch('http://93.184.215.14/');
  }

  /**
   * Oversized responses are refused, announced or not.
   */
  public function testOversizedResponsesAreRefused(): void {
    $big = str_repeat('a', PageFetcher::MAX_BYTES + 1);
    // Announced by Content-Length: refused before the body is read.
    try {
      $this->fetcher([new Response(200, ['Content-Length' => (string) strlen($big)], 'small')])->fetch('http://93.184.215.14/');
      $this->fail('An announced oversized body was accepted.');
    }
    catch (PageFetchException $e) {
      $this->assertStringContainsString('larger than 5 MB', $e->getMessage());
    }
    // Not announced: cut off while reading.
    $this->expectException(PageFetchException::class);
    $this->fetcher([new Response(200, [], $big)])->fetch('http://93.184.215.14/');
  }

  /**
   * The log message carries the URL, not raw placeholders.
   */
  public function testExceptionMessageIsFilledIn(): void {
    $e = PageFetchException::fetchFailed('https://example.com/x', new \RuntimeException('boom'));
    $this->assertSame('Could not fetch https://example.com/x: boom', $e->getMessage());
  }

  /**
   * Page chrome and scripts are dropped; whitespace is normalized.
   */
  public function testFetchTextStripsChrome(): void {
    $html = '<html><head><style>p{}</style></head><body><nav>Menu</nav><p>Terms   of
      service.</p><script>alert(1)</script><footer>©</footer></body></html>';
    $this->assertSame('Terms of service.', $this->fetcher([new Response(200, [], $html)])->fetchText('http://93.184.215.14/terms'));
  }

}
