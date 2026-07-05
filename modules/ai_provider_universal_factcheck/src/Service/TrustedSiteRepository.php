<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads the site's curated source list: trusted_site nodes with reputation.
 *
 * Editors curate web sources as published nodes of type 'trusted_site'
 * (shipped by the factcheck_trusted_sites recipe): a domain plus a
 * reputation from -10 to 10. Positive domains are preferred when searching
 * the web for evidence; negative ones are excluded. Sites without the
 * content type simply get no curation (empty lists = unrestricted search).
 *
 * Optional per-domain metadata (all empty when uncurated):
 * - bias: editorial lean (left/lean_left/center/lean_right/right), used to
 *   summarize the bias spread behind each claim's evidence.
 * - owner: parent organization; domains sharing an owner count as one
 *   independent source.
 * - assessments: provenance-tagged watchdog notes about the outlet, fed to
 *   the discrepancy-analysis prompt.
 */
class TrustedSiteRepository {

  /**
   * Per-request cache of the domain => profile map.
   */
  protected ?array $map = NULL;

  /**
   * Persistent cache id for the domain profile map.
   */
  protected const CACHE_ID = 'ai_provider_universal_factcheck.trusted_sites';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Domains with positive reputation, best first.
   *
   * @return string[]
   *   Domains, ordered by descending reputation.
   */
  public function includeDomains(): array {
    $positive = array_filter($this->reputationMap(), static fn (int $r) => $r > 0);
    arsort($positive);
    return array_keys($positive);
  }

  /**
   * Domains with negative reputation.
   *
   * @return string[]
   *   Domains to exclude.
   */
  public function excludeDomains(): array {
    return array_keys(array_filter($this->reputationMap(), static fn (int $r) => $r < 0));
  }

  /**
   * Reputation for a domain (0 for unknown/neutral).
   */
  public function reputation(string $domain): int {
    return $this->profile($domain)['reputation'];
  }

  /**
   * Full curated profile for a domain (neutral defaults when uncurated).
   *
   * @return array{reputation: int, bias: string, owner: string, assessments: string[]}
   *   The domain's profile.
   */
  public function profile(string $domain): array {
    return $this->profileMap()[strtolower($domain)]
      ?? ['reputation' => 0, 'bias' => '', 'owner' => '', 'assessments' => []];
  }

  /**
   * All curated domains mapped to their reputation.
   *
   * @return array<string, int>
   *   Domain to reputation map.
   */
  public function reputationMap(): array {
    return array_map(static fn (array $p): int => $p['reputation'], $this->profileMap());
  }

  /**
   * All curated domains mapped to their full profile.
   *
   * @return array<string, array{reputation: int, bias: string, owner: string, assessments: string[]}>
   *   Domain to profile map.
   */
  public function profileMap(): array {
    if ($this->map !== NULL) {
      return $this->map;
    }
    // Persistent cache, invalidated by core's bundle list tag whenever any
    // trusted_site node is created, updated or deleted.
    if ($cached = $this->cache->get(self::CACHE_ID)) {
      return $this->map = $cached->data;
    }
    $this->map = [];

    $storage = $this->entityTypeManager->getStorage('node');
    try {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'trusted_site')
        ->condition('status', 1)
        ->execute();
    }
    catch (\Throwable) {
      // No node module / no trusted_site type: curation is simply off.
      return $this->map;
    }

    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node->hasField('field_domain') || $node->get('field_domain')->isEmpty()) {
        continue;
      }
      // Accept bare domains or full URLs; store normalized host.
      $raw = trim((string) $node->get('field_domain')->value);
      $domain = strtolower(parse_url(str_contains($raw, '//') ? $raw : "https://$raw", PHP_URL_HOST) ?: $raw);
      if ($domain === '') {
        continue;
      }
      $value = static fn (string $field): string =>
        $node->hasField($field) ? trim((string) $node->get($field)->value) : '';
      $assessments = [];
      if ($node->hasField('field_assessments')) {
        foreach ($node->get('field_assessments')->getValue() as $item) {
          if (trim((string) ($item['value'] ?? '')) !== '') {
            $assessments[] = trim((string) $item['value']);
          }
        }
      }
      $this->map[$domain] = [
        'reputation' => $node->hasField('field_reputation') ? (int) $node->get('field_reputation')->value : 0,
        'bias' => $value('field_bias'),
        'owner' => $value('field_owner'),
        'assessments' => $assessments,
      ];
    }
    $this->cache->set(self::CACHE_ID, $this->map, CacheBackendInterface::CACHE_PERMANENT, ['node_list:trusted_site']);
    return $this->map;
  }

}
