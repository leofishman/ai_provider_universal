<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\ai_provider_universal_factcheck\Exception\PageFetchException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;

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
   * Largest number of redirects followed.
   */
  public const MAX_REDIRECTS = 5;

  /**
   * Fetches a URL and returns the raw body.
   *
   * Guards against SSRF: only http/https, and the host must resolve to a
   * public address (no loopback, private or link-local: blocks cloud
   * metadata endpoints and internal services). The connection goes to the
   * address that was checked, so a DNS server answering differently the
   * second time (DNS rebinding) cannot point it elsewhere. Redirects are
   * followed by hand so every hop is checked and pinned the same way.
   *
   * @throws \Drupal\ai_provider_universal_factcheck\Exception\PageFetchException
   *   When the URL is not public http(s), the response is larger than
   *   MAX_BYTES or the fetch fails.
   */
  public function fetch(string $url): string {
    for ($hop = 0; $hop <= static::MAX_REDIRECTS; $hop++) {
      $response = $this->get($url, $this->assertPublicUrl($url));
      $location = $response->getHeaderLine('Location');
      if ($response->getStatusCode() < 300 || $response->getStatusCode() >= 400 || $location === '') {
        return $this->read($response, $url);
      }
      $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
    }
    throw PageFetchException::fetchFailed($url, new \RuntimeException('Too many redirects.'));
  }

  /**
   * Sends one GET to a checked address, without following redirects.
   */
  protected function get(string $url, string $ip): ResponseInterface {
    $parts = parse_url($url);
    $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    try {
      return $this->httpClient->request('GET', $url, [
        'timeout' => 30,
        // Streamed, so an oversized body is cut off instead of loaded whole.
        'stream' => TRUE,
        'allow_redirects' => FALSE,
        // ponytail: pinning needs Guzzle's curl handler (Drupal's default
        // whenever the curl extension is loaded); the stream handler
        // ignores it and resolves again.
        'curl' => [CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':' . $ip]],
      ]);
    }
    catch (\Throwable $e) {
      throw PageFetchException::fetchFailed($url, $e);
    }
  }

  /**
   * Reads a response body up to MAX_BYTES.
   */
  protected function read(ResponseInterface $response, string $url): string {
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

  /**
   * Returns the public address the URL's host resolves to, or throws.
   */
  protected function assertPublicUrl(string $url): string {
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    if (!filter_var($url, FILTER_VALIDATE_URL)
      || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE)
      || $host === '') {
      throw PageFetchException::notPublic();
    }
    // IPv4 only: a host with no A record (or an IPv6 literal) is refused.
    $ip = gethostbyname(trim($host, '[]'));
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      throw PageFetchException::internalAddress();
    }
    return $ip;
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
