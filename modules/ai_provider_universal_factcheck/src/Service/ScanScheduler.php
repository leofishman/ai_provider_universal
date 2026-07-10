<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai_provider_universal_factcheck\Entity\ScanProfileInterface;
use Drupal\ai_provider_universal_factcheck\Utility\ScanText;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Request-path half of scheduled scans: cheap filters, then enqueue.
 *
 * Runs on node insert/update. Everything here must stay cheap — bundle,
 * operation and status checks, a text comparison against the pre-save
 * revision, and a key-value cooldown lookup. No LLM, no HTTP, no result
 * queries; the expensive work happens in the aip_content_review queue
 * worker on cron. A failure here is logged and swallowed: saving content
 * is never blocked by review plumbing.
 */
class ScanScheduler {

  /**
   * The queue consumed by ContentReviewWorker.
   */
  const QUEUE = 'aip_content_review';

  /**
   * Key-value collection holding per-(profile, node) cooldown flags.
   */
  const COOLDOWN_COLLECTION = 'aip_scan_cooldown';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly QueueFactory $queueFactory,
    protected readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Enqueues the node for every matching enabled profile.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The saved node.
   * @param string $operation
   *   Either 'insert' or 'update'.
   */
  public function onNodeSave(NodeInterface $node, string $operation): void {
    try {
      $this->doEnqueue($node, $operation);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not enqueue node @nid for content review: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Applies the cheap filters and creates the queue items.
   */
  protected function doEnqueue(NodeInterface $node, string $operation): void {
    $profiles = array_filter(
      $this->entityTypeManager->getStorage('aip_scan_profile')->loadMultiple(),
      static fn (ScanProfileInterface $profile): bool => $profile->appliesTo($node, $operation),
    );
    if (!$profiles) {
      return;
    }

    // On update, skip when no scannable text changed (metadata-only saves,
    // moderation transitions, …). The pre-save revision is already in
    // memory, so the comparison is free.
    if ($operation === 'update' && !$this->textChanged($node)) {
      return;
    }

    $cooldowns = $this->keyValueExpirable->get(self::COOLDOWN_COLLECTION);
    $queue = $this->queueFactory->get(self::QUEUE);
    foreach ($profiles as $profile) {
      $key = $profile->id() . ':' . $node->id();
      if ($cooldowns->has($key)) {
        continue;
      }
      // ponytail: the cooldown flag doubles as pending-item dedupe (a
      // re-save within the TTL never enqueues twice). Exact dedupe would
      // need queue inspection; add it if duplicate scans ever matter.
      $cooldowns->setWithExpire($key, TRUE, max($profile->getCooldown(), 60));
      $queue->createItem([
        'nid' => $node->id(),
        'profile_id' => $profile->id(),
        'uid' => (int) $this->currentUser->id(),
      ]);
    }
  }

  /**
   * Whether the scannable text differs from the pre-save revision.
   */
  protected function textChanged(NodeInterface $node): bool {
    // getOriginal() landed in core 11.2; older cores expose the property.
    $original = method_exists($node, 'getOriginal')
      ? $node->getOriginal()
      : ($node->original ?? NULL);
    if (!$original instanceof NodeInterface) {
      return TRUE;
    }
    return ScanText::extract($node) !== ScanText::extract($original);
  }

}
