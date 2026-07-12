<?php

namespace Drupal\ai_provider_universal_factcheck\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\node\NodeInterface;

/**
 * Interface for scan profile config entities.
 */
interface ScanProfileInterface extends ConfigEntityInterface {

  /**
   * Node bundles this profile scans (empty = none, profiles are explicit).
   *
   * @return string[]
   *   Node type machine names.
   */
  public function getBundles(): array;

  /**
   * Entity operations that enqueue a scan ('insert', 'update').
   *
   * @return string[]
   *   The operations.
   */
  public function getOperations(): array;

  /**
   * Whether only published nodes are scanned.
   */
  public function isPublishedOnly(): bool;

  /**
   * Seconds to wait before the same node can be enqueued again.
   */
  public function getCooldown(): int;

  /**
   * Check configuration keyed by check id.
   *
   * Keys: ai_likelihood {enabled, alert_threshold}, readability
   * {enabled, alert_below}, factcheck {enabled, alert_below},
   * plagiarism {enabled, alert_min_hits}.
   *
   * @return array<string, array>
   *   The check settings; missing checks mean disabled.
   */
  public function getChecks(): array;

  /**
   * When to dispatch the content review event: always|threshold|never.
   */
  public function getEventOn(): string;

  /**
   * Whether this profile applies to the given node and operation.
   *
   * Cheap request-path filter: bundle, operation and publication status
   * only — no field access, no queries.
   */
  public function appliesTo(NodeInterface $node, string $operation): bool;

}
