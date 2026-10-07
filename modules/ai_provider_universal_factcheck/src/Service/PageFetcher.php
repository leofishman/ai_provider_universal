<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\ai_provider_universal_factcheck\Exception\PageFetchException;
use GuzzleHttp\ClientInterface;

/**
 * Fetches a public web page and reduces it to plain text.
 *
 * Shared by the standalone scan form and any submodule that analyses pages
 * by URL. Errors are thrown as PageFetchException, whose translated
 * message is ready to show to the user.
 */
class PageFetcher {

  /**
   * Largest response read, in bytes; the rest is never loaded into memory.
   */
  public const MAX_BYTES = 5000000;

  public function __construct(
    protected ClientInterface $httpClient,
  ) {}

  /**
   * Fetches a URL and returns its text content.
   *
   * @throws \Drupal\ai_provider_universal_factcheck\Exception\PageFetchException
   *   When the URL is not public http(s) or the fetch fails.
   */
  public function fetchText(string $url): string {
    return $this->toText($this->fetch($url));
  }

  /**
   * Fetches a URL and returns the raw body.
   *
   * Guards against SSRF: only http/https, and the host must not resolve
   * to a loopback, private or link-local address (blocks cloud metadata
   * endpoints and internal services).
   *
   * @throws \Drupal\ai_provider_universal_factcheck\Exception\PageFetchException
   *   When the URL is not public http(s), the response is larger than
   *   MAX_BYTES or the fetch fails.
   */
  public function fetch(string $url): string {
    $this->assertPublicUrl($url);
    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 30,
        // Streamed, so an oversized body is cut off instead of loaded whole.
        'stream' => TRUE,
        // Every redirect hop gets the same check, or a public URL could
        // bounce the request to an internal address.
        'allow_redirects' => [
          'max' => 5,
          'protocols' => ['http', 'https'],
          'on_redirect' => fn($request, $response, $uri) => $this->assertPublicUrl((string) $uri),
        ],
      ]);
      if ((int) $response->getHeaderLine('Content-Length') > static::MAX_BYTES) {
        throw PageFetchException::tooLarge($url, static::MAX_BYTES);
      }
      $body = $response->getBody();
      $data = '';
      while (!$body->eof() && strlen($data) <= static::MAX_BYTES) {
        $data .= $body->read(65536);
      }
      if (strlen($data) > static::MAX_BYTES) {
        throw PageFetchException::tooLarge($url, static::MAX_BYTES);
      }
      return $data;
    }
    catch (PageFetchException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      // Guzzle wraps exceptions thrown from on_redirect.
      if ($e->getPrevious() instanceof PageFetchException) {
        throw $e->getPrevious();
      }
      throw PageFetchException::fetchFailed($url, $e);
    }
  }

  /**
   * Throws unless the URL is http(s) on a host with a public address.
   */
  protected function assertPublicUrl(string $url): void {
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    if (!filter_var($url, FILTER_VALIDATE_URL)
      || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE)
      || $host === '') {
      throw PageFetchException::notPublic();
    }
    // ponytail: resolve-then-fetch leaves a DNS-rebinding window; a
    // pinning HTTP middleware is the upgrade if this ever fetches URLs
    // supplied by untrusted users.
    $ip = gethostbyname(trim($host, '[]'));
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      throw PageFetchException::internalAddress();
    }
  }

  /**
   * Reduces HTML (or plain text) to whitespace-normalized text.
   *
   * Drops non-content markup before stripping tags, so menus and scripts
   * don't pollute the text.
   */
  public function toText(string $html): string {
    $html = preg_replace('/<(script|style|nav|header|footer)\b[^>]*>.*?<\/\1>/is', ' ', $html);
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
  }

}
