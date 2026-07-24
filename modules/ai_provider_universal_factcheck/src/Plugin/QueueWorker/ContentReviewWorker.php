<?php

namespace Drupal\ai_provider_universal_factcheck\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_universal_factcheck\Entity\ScanProfileInterface;
use Drupal\ai_provider_universal_factcheck\Service\ScanRunner;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs one scheduled content scan per queue item.
 *
 * Items are created by ScanScheduler on node save. Stale items — node or
 * profile deleted, profile disabled, node no longer matching — are dropped
 * silently: the queue reflects intent at save time, the worker re-checks
 * reality at run time.
 */
#[QueueWorker(
  id: 'aip_content_review',
  title: new TranslatableMarkup('Scheduled content review scans'),
  // Heavy profiles (factcheck + plagiarism) can exceed 60s per item; cron
  // will resume remaining items on the next run if the budget is spent.
  cron: ['time' => 120],
)]
class ContentReviewWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    // Not readonly: QueueWorkerBase brings in DependencySerializationTrait,
    // whose __wakeup() cannot reinitialize readonly properties declared here
    // on PHP < 8.4.
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScanRunner $scanRunner,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get(ScanRunner::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $node = $this->entityTypeManager->getStorage('node')->load($data['nid'] ?? 0);
    $profile = $this->entityTypeManager->getStorage('aip_scan_profile')->load($data['profile_id'] ?? '');
    if (!$node instanceof NodeInterface
      || !$profile instanceof ScanProfileInterface
      || !$profile->status()
      || !in_array($node->bundle(), $profile->getBundles(), TRUE)
      || ($profile->isPublishedOnly() && !$node->isPublished())) {
      return;
    }
    $this->scanRunner->run($node, $profile, (int) ($data['uid'] ?? 0));
  }

}
