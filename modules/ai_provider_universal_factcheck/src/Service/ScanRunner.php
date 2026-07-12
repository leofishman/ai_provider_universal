<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_provider_universal_factcheck\Entity\ScanProfileInterface;
use Drupal\ai_provider_universal_factcheck\Event\ContentReviewEvent;
use Drupal\ai_provider_universal_factcheck\Utility\ScanText;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Worker-side half of scheduled scans: run checks, persist, signal.
 *
 * Reuses the same check services as the manual Content scan tab and writes
 * to the same aip_factcheck_result history. Checks run cheapest-first and
 * each failure is isolated — one broken external API never voids the rest
 * of the scan. Unconfigured checks (no detector model, no API key) return
 * NULL and simply appear as "no result".
 */
class ScanRunner {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ReadabilityScorer $readabilityScorer,
    protected readonly AiDetector $aiDetector,
    protected readonly FactChecker $factChecker,
    protected readonly PlagiarismChecker $plagiarismChecker,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Scans one node against one profile.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to scan.
   * @param \Drupal\ai_provider_universal_factcheck\Entity\ScanProfileInterface $profile
   *   The profile whose checks and thresholds apply.
   * @param int $uid
   *   The user whose save enqueued the scan.
   */
  public function run(NodeInterface $node, ScanProfileInterface $profile, int $uid): void {
    $text = ScanText::extract($node);
    if (mb_strlen($text) < 10) {
      return;
    }

    $checks = $profile->getChecks();
    $results = [];
    $thresholds_hit = [];

    // Cheapest first: local readability, one-call detector, then the
    // multi-call fact check and the external plagiarism search.
    if (!empty($checks['readability']['enabled'])) {
      $results['readability'] = $this->guarded('readability', fn (): ?array => $this->readabilityScorer->score($text));
      $score = $results['readability']['score'] ?? NULL;
      if ($score !== NULL && $score < (float) ($checks['readability']['alert_below'] ?? 30)) {
        $thresholds_hit[] = 'readability';
      }
    }
    if (!empty($checks['ai_likelihood']['enabled'])) {
      $results['ai'] = $this->guarded('ai_likelihood', fn (): ?array => $this->aiDetector->detect($text));
      $score = $results['ai']['score'] ?? NULL;
      if ($score !== NULL && $score >= (int) ($checks['ai_likelihood']['alert_threshold'] ?? 70)) {
        $thresholds_hit[] = 'ai_likelihood';
      }
    }
    if (!empty($checks['factcheck']['enabled']) && $this->factChecker->isConfigured()) {
      $results['factcheck'] = $this->guarded('factcheck', fn (): ?array => $this->factChecker->verify((string) $node->label(), $text));
      $score = $results['factcheck']['score'] ?? NULL;
      if ($score !== NULL && $score < (float) ($checks['factcheck']['alert_below'] ?? 0.5)) {
        $thresholds_hit[] = 'factcheck';
      }
    }
    if (!empty($checks['plagiarism']['enabled'])) {
      $results['plagiarism'] = $this->guarded('plagiarism', fn (): ?array => $this->plagiarismChecker->check($text));
      if ($results['plagiarism'] !== NULL
        && count($results['plagiarism']) >= (int) ($checks['plagiarism']['alert_min_hits'] ?? 1)) {
        $thresholds_hit[] = 'plagiarism';
      }
    }

    $scores = [
      'readability' => $results['readability']['score'] ?? NULL,
      'ai_likelihood' => $results['ai']['score'] ?? NULL,
      'factcheck' => $results['factcheck']['score'] ?? NULL,
      'plagiarism_matches' => isset($results['plagiarism']) && $results['plagiarism'] !== NULL
        ? count($results['plagiarism'])
        : NULL,
    ];

    // Same history as the manual Content scan tab.
    $result = $this->entityTypeManager->getStorage('aip_factcheck_result')->create([
      'subject' => (string) $node->label(),
      'node' => $node->id(),
      'uid' => $uid,
      'score' => $scores['factcheck'],
      'ai_score' => $scores['ai_likelihood'],
      'readability' => $scores['readability'],
      'plagiarism_matches' => $scores['plagiarism_matches'],
      'details' => $results + [
        'profile_id' => $profile->id(),
        'source' => 'scheduled_scan',
        'thresholds_hit' => $thresholds_hit,
      ],
    ]);
    $result->save();

    $event_on = $profile->getEventOn();
    if ($event_on === 'always' || ($event_on === 'threshold' && $thresholds_hit !== [])) {
      $this->eventDispatcher->dispatch(
        new ContentReviewEvent(
          $node->getEntityTypeId(),
          $node->id(),
          $node->bundle(),
          $uid,
          (string) $profile->id(),
          $scores,
          $thresholds_hit,
          $result->id(),
        ),
        ContentReviewEvent::EVENT_NAME,
      );
    }
  }

  /**
   * Runs one check, isolating its failures from the rest of the scan.
   */
  protected function guarded(string $check_id, callable $check): ?array {
    try {
      return $check();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Scheduled scan check @check failed: @message', [
        '@check' => $check_id,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
