<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Searches the web for verbatim copies of the text's longest sentences.
 *
 * Uses the Serper.dev Google Search API (key from the 'plagiarism_key' Key
 * entity): each candidate sentence is searched as an exact phrase; any hit
 * is a passage that exists verbatim elsewhere on the web. Without a key the
 * check is unavailable (returns NULL).
 */
class PlagiarismChecker {

  protected const ENDPOINT = 'https://google.serper.dev/search';

  /**
   * How many of the longest sentences to check. One API call each.
   */
  protected const SENTENCES = 5;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether a Serper API key is configured.
   */
  public function isConfigured(): bool {
    return (bool) $this->apiKey();
  }

  /**
   * Checks a text for verbatim web matches.
   *
   * @return array<int, array{sentence: string, url: string, title: string, snippet: string}>|null
   *   One row per sentence found verbatim elsewhere (empty array = nothing
   *   found), or NULL when no API key is configured.
   */
  public function check(string $text): ?array {
    $key = $this->apiKey();
    if (!$key) {
      return NULL;
    }

    // Longest sentences are the most distinctive (short ones match anywhere).
    $sentences = preg_split('/(?<=[.!?…])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $sentences = array_filter($sentences, static fn (string $s) => mb_strlen($s) >= 60);
    usort($sentences, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
    $sentences = array_slice($sentences, 0, self::SENTENCES);

    $matches = [];
    foreach ($sentences as $sentence) {
      try {
        // Serper caps queries; a 25-word exact phrase is plenty distinctive.
        $phrase = implode(' ', array_slice(explode(' ', $sentence), 0, 25));
        $response = $this->httpClient->request('POST', self::ENDPOINT, [
          'headers' => ['X-API-KEY' => $key, 'Content-Type' => 'application/json'],
          'json' => ['q' => '"' . $phrase . '"', 'num' => 3],
          'timeout' => 15,
        ]);
        $data = json_decode((string) $response->getBody(), TRUE);
        foreach ($data['organic'] ?? [] as $hit) {
          $matches[] = [
            'sentence' => $sentence,
            'url' => (string) ($hit['link'] ?? ''),
            'title' => (string) ($hit['title'] ?? ''),
            'snippet' => (string) ($hit['snippet'] ?? ''),
          ];
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Plagiarism search failed: @message', ['@message' => $e->getMessage()]);
      }
    }
    return $matches;
  }

  /**
   * The Serper API key value, or '' when unset.
   */
  protected function apiKey(): string {
    $keyId = (string) $this->configFactory->get('ai_provider_universal_factcheck.settings')->get('plagiarism_key');
    if (!$keyId) {
      return '';
    }
    return (string) ($this->keyRepository->getKey($keyId)?->getKeyValue() ?? '');
  }

}
