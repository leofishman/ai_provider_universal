<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Reads the site's curated source list: trusted_site nodes with reputation.
 *
 * Editors curate web sources as published nodes of type 'trusted_site'
 * (shipped by the factcheck_trusted_sites recipe): a domain plus a
 * reputation from -10 to 10. Positive domains are preferred when searching
 * the web for evidence; negative ones are excluded. Sites without the
 * content type simply get no curation (empty lists = unrestricted search).
 */
class TrustedSiteRepository {

  /**
   * Per-request cache of the domain => reputation map.
   */
  protected ?array $map = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
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
    return $this->reputationMap()[strtolower($domain)] ?? 0;
  }

  /**
   * All curated domains mapped to their reputation.
   *
   * @return array<string, int>
   *   Domain to reputation map.
   */
  public function reputationMap(): array {
    if ($this->map !== NULL) {
      return $this->map;
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
      $reputation = $node->hasField('field_reputation') ? (int) $node->get('field_reputation')->value : 0;
      if ($domain !== '') {
        $this->map[$domain] = $reputation;
      }
    }
    return $this->map;
  }

}
