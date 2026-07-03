<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Retrieves supporting passages for a claim.
 *
 * Cascade:
 * 1. The configured Search API index (site content, e.g. an ai_search
 *    vector index) — the site's own knowledge always wins.
 * 2. When the index yields nothing and a Tavily API key is configured, the
 *    web via Tavily search — restricted by the site's curated trusted_site
 *    nodes: positive-reputation domains are searched preferentially,
 *    negative-reputation domains are excluded.
 *
 * Without an index or key it returns no evidence and the FactChecker falls
 * back to model-only verification.
 *
 * Negative-reputation domains are excluded from supporting evidence but are
 * searchable on purpose via retrieveDistrusted(): a claim echoed by known
 * misinformation sites is a signal against it, and the FactChecker lowers
 * the support score accordingly.
 */
class EvidenceRetriever {

  protected const TAVILY_ENDPOINT = 'https://api.tavily.com/search';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected ClientInterface $httpClient,
    protected KeyRepositoryInterface $keyRepository,
    protected TrustedSiteRepository $trustedSites,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns up to $limit text passages relevant to the claim.
   *
   * @return string[]
   *   Plain-text passages; empty when no evidence source is configured or
   *   nothing was found.
   */
  public function retrieve(string $claim, int $limit = 3): array {
    $passages = $this->retrieveLocal($claim, $limit);
    if (!$passages) {
      $passages = $this->retrieveWeb($claim, $limit);
    }
    return $passages;
  }

  /**
   * Passages from the configured Search API index.
   *
   * @return string[]
   *   Plain-text passages from the index.
   */
  protected function retrieveLocal(string $claim, int $limit): array {
    $indexId = $this->settings()->get('evidence_index');
    if (!$indexId || !$this->moduleHandler->moduleExists('search_api')) {
      return [];
    }

    try {
      $index = $this->entityTypeManager->getStorage('search_api_index')->load($indexId);
      if (!$index) {
        return [];
      }

      /** @var \Drupal\search_api\IndexInterface $index */
      $query = $index->query()
        ->keys($claim)
        ->range(0, $limit);
      // Verification is a server-side concern, not a user-facing search: the
      // checker judges answers against published, indexed content regardless
      // of who triggered the request. ai_search runs entity access checks by
      // default (and would return nothing for anonymous/cron contexts), so
      // bypass them and request the raw chunks.
      $query->setOption('search_api_bypass_access', TRUE);
      $query->setOption('search_api_ai_get_chunks_result', TRUE);
      $results = $query->execute();

      $passages = [];
      foreach ($results as $item) {
        // ai_search attaches the matched chunk as extra data; excerpt and
        // entity label are fallbacks for other Search API backends.
        $text = $item->getExtraData('content') ?: $item->getExcerpt();
        if (!$text) {
          $entity = $item->getOriginalObject()?->getValue();
          $text = $entity && method_exists($entity, 'label') ? (string) $entity->label() : '';
        }
        if ($text) {
          $passages[] = strip_tags(is_array($text) ? implode(' ', $text) : (string) $text);
        }
      }
      return $passages;
    }
    catch (\Throwable) {
      // Evidence is best-effort; verification degrades to the next source.
      return [];
    }
  }

  /**
   * Passages from the web via Tavily, honoring trusted-site curation.
   *
   * @return string[]
   *   Source-prefixed passages from the web.
   */
  protected function retrieveWeb(string $claim, int $limit): array {
    $keyId = (string) $this->settings()->get('tavily_key');
    if (!$keyId) {
      return [];
    }
    $apiKey = (string) ($this->keyRepository->getKey($keyId)?->getKeyValue() ?? '');
    if (!$apiKey) {
      return [];
    }

    $payload = [
      'query' => $claim,
      'max_results' => $limit,
      'search_depth' => 'basic',
    ];
    // Tavily caps domain lists at 300 entries; a curated list won't get
    // close, but slice defensively.
    if ($include = array_slice($this->trustedSites->includeDomains(), 0, 300)) {
      $payload['include_domains'] = $include;
    }
    if ($exclude = array_slice($this->trustedSites->excludeDomains(), 0, 300)) {
      $payload['exclude_domains'] = $exclude;
    }

    $passages = $this->tavilySearch($payload, $apiKey);

    // Curated-only search can come back empty when the include list is too
    // narrow for the claim; retry once unrestricted (still minus excluded).
    if (!$passages && !empty($payload['include_domains'])) {
      unset($payload['include_domains']);
      $passages = $this->tavilySearch($payload, $apiKey);
    }
    return $passages;
  }

  /**
   * Passages about the claim from negative-reputation (distrusted) domains.
   *
   * @return string[]
   *   Source-prefixed passages; empty when no Tavily key or no distrusted
   *   domains are configured.
   */
  public function retrieveDistrusted(string $claim, int $limit = 3): array {
    $keyId = (string) $this->settings()->get('tavily_key');
    $domains = array_slice($this->trustedSites->excludeDomains(), 0, 300);
    if (!$keyId || !$domains) {
      return [];
    }
    $apiKey = (string) ($this->keyRepository->getKey($keyId)?->getKeyValue() ?? '');
    if (!$apiKey) {
      return [];
    }

    return $this->tavilySearch([
      'query' => $claim,
      'max_results' => $limit,
      'search_depth' => 'basic',
      'include_domains' => $domains,
    ], $apiKey);
  }

  /**
   * Executes one Tavily search and maps results to source-prefixed passages.
   *
   * @return string[]
   *   Source-prefixed passages.
   */
  protected function tavilySearch(array $payload, string $apiKey): array {
    try {
      $response = $this->httpClient->request('POST', self::TAVILY_ENDPOINT, [
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'timeout' => 20,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->error('Tavily evidence search failed: @message', ['@message' => $e->getMessage()]);
      return [];
    }

    $passages = [];
    foreach ($data['results'] ?? [] as $result) {
      $content = trim((string) ($result['content'] ?? ''));
      if ($content === '') {
        continue;
      }
      // Prefix the source so the checker (and any future citation UI) can
      // see where the passage came from.
      $url = (string) ($result['url'] ?? '');
      $passages[] = ($url ? "[$url] " : '') . $content;
    }
    return $passages;
  }

  /**
   * Module settings.
   */
  protected function settings() {
    return $this->configFactory->get('ai_provider_universal_factcheck.settings');
  }

}
