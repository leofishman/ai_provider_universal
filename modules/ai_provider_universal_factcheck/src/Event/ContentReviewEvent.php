<?php

namespace Drupal\ai_provider_universal_factcheck\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Fired after a scheduled scan, per the profile's event setting.
 *
 * A review signal for ECA/Workflow (set moderation state, mail editors, …)
 * — never a save blocker, and never a statement of AI origin (that is the
 * provenance event's job). Source is always 'scheduled_scan' so subscribers
 * can tell it apart from manual Content scan runs.
 */
class ContentReviewEvent extends Event {

  const EVENT_NAME = 'ai_provider_universal_factcheck.content_review';

  /**
   * Constructs the review event.
   *
   * @param string $entityTypeId
   *   The scanned entity type id (currently always 'node').
   * @param string|int $entityId
   *   The scanned entity id.
   * @param string $bundle
   *   The entity bundle.
   * @param int $uid
   *   The user whose save enqueued the scan.
   * @param string $profileId
   *   The aip_scan_profile that ran.
   * @param array<string, int|float|null> $scores
   *   Scores per executed check (readability, ai_likelihood, factcheck,
   *   plagiarism_matches); NULL when a check ran but produced no result.
   * @param string[] $thresholdsHit
   *   Check ids whose alert threshold was crossed; empty on a clean scan.
   * @param string|int $resultId
   *   The persisted aip_factcheck_result entity id.
   */
  public function __construct(
    protected string $entityTypeId,
    protected string|int $entityId,
    protected string $bundle,
    protected int $uid,
    protected string $profileId,
    protected array $scores,
    protected array $thresholdsHit,
    protected string|int $resultId,
  ) {
  }

  /**
   * The scanned entity type id.
   */
  public function getEntityTypeId(): string {
    return $this->entityTypeId;
  }

  /**
   * The scanned entity id.
   */
  public function getEntityId(): string|int {
    return $this->entityId;
  }

  /**
   * The entity bundle.
   */
  public function getBundle(): string {
    return $this->bundle;
  }

  /**
   * The user whose save enqueued the scan.
   */
  public function getUid(): int {
    return $this->uid;
  }

  /**
   * The scan profile id.
   */
  public function getProfileId(): string {
    return $this->profileId;
  }

  /**
   * Scores per executed check.
   *
   * @return array<string, int|float|null>
   *   The scores.
   */
  public function getScores(): array {
    return $this->scores;
  }

  /**
   * Check ids whose alert threshold was crossed.
   *
   * @return string[]
   *   The check ids; empty on a clean scan.
   */
  public function getThresholdsHit(): array {
    return $this->thresholdsHit;
  }

  /**
   * The persisted aip_factcheck_result entity id.
   */
  public function getResultId(): string|int {
    return $this->resultId;
  }

  /**
   * Distinguishes scheduled scans from manual/provenance signals.
   */
  public function getSource(): string {
    return 'scheduled_scan';
  }

}
