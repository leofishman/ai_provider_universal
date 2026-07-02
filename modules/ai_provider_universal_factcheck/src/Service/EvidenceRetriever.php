<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Retrieves supporting passages for a claim from a Search API index.
 *
 * When the site has an ai_search (vector) index configured, verification is
 * grounded in the site's own content instead of a second model's opinion.
 * Without an index (or when search_api is absent) it returns no evidence and
 * the FactChecker falls back to model-only verification.
 */
class EvidenceRetriever {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Returns up to $limit text passages relevant to the claim.
   *
   * @return string[]
   *   Plain-text passages; empty when no index is configured or usable.
   */
  public function retrieve(string $claim, int $limit = 3): array {
    $indexId = $this->configFactory->get('ai_provider_universal_factcheck.settings')->get('evidence_index');
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
      // Evidence is best-effort; verification degrades to model-only.
      return [];
    }
  }

}
