<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_universal_factcheck\Entity\ScanProfile;
use Drupal\ai_provider_universal_factcheck\Event\ContentReviewEvent;
use Drupal\ai_provider_universal_factcheck\Plugin\QueueWorker\ContentReviewWorker;
use Drupal\ai_provider_universal_factcheck\Service\ScanRunner;
use Drupal\ai_provider_universal_factcheck\Service\ScanScheduler;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests scheduled content scans: enqueue filters, worker, review event.
 *
 * @group ai_provider_universal
 */
#[CoversClass(ScanScheduler::class)]
#[CoversClass(ScanRunner::class)]
#[CoversClass(ContentReviewWorker::class)]
#[CoversClass(ScanProfile::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class ScheduledScanTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'key',
    'ai',
    'ai_provider_universal',
    'ai_provider_universal_router',
    'ai_provider_universal_factcheck',
  ];

  /**
   * Review events collected by the test listener.
   *
   * @var \Drupal\ai_provider_universal_factcheck\Event\ContentReviewEvent[]
   */
  protected array $collected = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('aip_factcheck_result');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'node',
      'type' => 'text_long',
    ])->save();
    foreach (['article', 'page'] as $bundle) {
      FieldConfig::create([
        'field_name' => 'field_body',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Body',
      ])->save();
    }

    $this->container->get('event_dispatcher')->addListener(
      ContentReviewEvent::EVENT_NAME,
      function (ContentReviewEvent $event): void {
        $this->collected[] = $event;
      },
    );
  }

  /**
   * Creates an enabled readability-only profile.
   */
  protected function createProfile(array $values = []): ScanProfile {
    $profile = ScanProfile::create($values + [
      'id' => 'editorial',
      'label' => 'Editorial',
      'status' => TRUE,
      'bundles' => ['article'],
      'operations' => ['insert', 'update'],
      'published_only' => TRUE,
      'cooldown' => 3600,
      'checks' => [
        'readability' => ['enabled' => TRUE, 'alert_below' => 100],
      ],
      'event_on' => 'threshold',
    ]);
    $profile->save();
    return $profile;
  }

  /**
   * Creates a published article with enough text to scan.
   */
  protected function createArticle(array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'article',
      'title' => 'A test article',
      'status' => 1,
      'field_body' => [
        'value' => 'This is a long enough passage of plain text. It contains several ordinary sentences. The readability scorer needs at least ten words to produce a score.',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();
    return $node;
  }

  /**
   * The pending item count in the review queue.
   */
  protected function queueCount(): int {
    return $this->container->get('queue')->get(ScanScheduler::QUEUE)->numberOfItems();
  }

  /**
   * A matching insert enqueues exactly one item with the node coordinates.
   */
  public function testInsertEnqueues(): void {
    $this->createProfile();
    $node = $this->createArticle();

    $this->assertSame(1, $this->queueCount());
    $item = $this->container->get('queue')->get(ScanScheduler::QUEUE)->claimItem();
    $this->assertSame($node->id(), (string) $item->data['nid']);
    $this->assertSame('editorial', $item->data['profile_id']);
  }

  /**
   * Non-matching saves never enqueue: wrong bundle, disabled, unpublished.
   */
  public function testNonMatchingSavesDoNotEnqueue(): void {
    $this->createProfile();

    $this->createArticle(['type' => 'page']);
    $this->assertSame(0, $this->queueCount(), 'Wrong bundle is skipped.');

    $this->createArticle(['status' => 0, 'title' => 'Draft']);
    $this->assertSame(0, $this->queueCount(), 'Unpublished is skipped when published_only.');

    ScanProfile::load('editorial')->setStatus(FALSE)->save();
    $this->createArticle(['title' => 'While disabled']);
    $this->assertSame(0, $this->queueCount(), 'Disabled profile is skipped.');
  }

  /**
   * The cooldown flag blocks re-enqueueing the same node.
   */
  public function testCooldownBlocksReEnqueue(): void {
    $this->createProfile();
    $node = $this->createArticle();
    $this->assertSame(1, $this->queueCount());

    $node->set('field_body', [
      'value' => 'Completely different text now, still long enough for the scorer. Several new sentences follow here. This clearly changed the scannable content of the node.',
      'format' => 'plain_text',
    ]);
    $node->save();
    $this->assertSame(1, $this->queueCount(), 'Within the cooldown nothing is re-enqueued.');
  }

  /**
   * Updates only enqueue when the scannable text actually changed.
   */
  public function testUpdateRequiresTextChange(): void {
    $this->createProfile(['operations' => ['update']]);
    $node = $this->createArticle();
    $this->assertSame(0, $this->queueCount(), 'Insert does not match an update-only profile.');

    $node->set('title', 'Metadata-only change');
    $node->save();
    $this->assertSame(0, $this->queueCount(), 'Unchanged text is not enqueued.');

    $node->set('field_body', [
      'value' => 'Completely different text now, still long enough for the scorer. Several new sentences follow here. This clearly changed the scannable content of the node.',
      'format' => 'plain_text',
    ]);
    $node->save();
    $this->assertSame(1, $this->queueCount(), 'Changed text is enqueued.');
  }

  /**
   * The worker scans, persists a result row and fires the review event.
   */
  public function testWorkerScansPersistsAndDispatches(): void {
    $this->createProfile();
    $node = $this->createArticle();

    $queue = $this->container->get('queue')->get(ScanScheduler::QUEUE);
    $item = $queue->claimItem();
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance('aip_content_review')
      ->processItem($item->data);

    $results = $this->container->get('entity_type.manager')
      ->getStorage('aip_factcheck_result')->loadMultiple();
    $this->assertCount(1, $results);
    $result = reset($results);
    $this->assertSame($node->id(), $result->get('node')->target_id);
    $this->assertNotNull($result->get('readability')->value);

    // alert_below 100 means any real Flesch score crosses the threshold.
    $this->assertCount(1, $this->collected);
    $event = $this->collected[0];
    $this->assertSame('node', $event->getEntityTypeId());
    $this->assertSame($node->id(), (string) $event->getEntityId());
    $this->assertSame('editorial', $event->getProfileId());
    $this->assertSame(['readability'], $event->getThresholdsHit());
    $this->assertSame((string) $result->id(), (string) $event->getResultId());
    $this->assertSame('scheduled_scan', $event->getSource());
    $this->assertNull($event->getScores()['factcheck'], 'Disabled checks report no score.');
  }

  /**
   * With event_on never the result is stored but no event fires.
   */
  public function testEventOnNeverStoresSilently(): void {
    $this->createProfile(['event_on' => 'never']);
    $this->createArticle();

    $item = $this->container->get('queue')->get(ScanScheduler::QUEUE)->claimItem();
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance('aip_content_review')
      ->processItem($item->data);

    $this->assertCount(1, $this->container->get('entity_type.manager')
      ->getStorage('aip_factcheck_result')->loadMultiple());
    $this->assertSame([], $this->collected);
  }

  /**
   * With event_on always the event fires even when no threshold is crossed.
   */
  public function testEventOnAlwaysDispatches(): void {
    // With alert_below 0 the readability threshold is never crossed, so
    // thresholds_hit stays empty and only event_on drives the dispatch.
    $this->createProfile([
      'event_on' => 'always',
      'checks' => [
        'readability' => ['enabled' => TRUE, 'alert_below' => 0],
      ],
    ]);
    $this->createArticle();

    $item = $this->container->get('queue')->get(ScanScheduler::QUEUE)->claimItem();
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance('aip_content_review')
      ->processItem($item->data);

    $this->assertCount(1, $this->collected);
    $this->assertSame([], $this->collected[0]->getThresholdsHit());
  }

  /**
   * Stale queue items (deleted node or profile) are dropped silently.
   */
  public function testStaleItemsAreDropped(): void {
    $this->createProfile();
    $node = $this->createArticle();
    $worker = $this->container->get('plugin.manager.queue_worker')
      ->createInstance('aip_content_review');

    $worker->processItem(['nid' => 99999, 'profile_id' => 'editorial', 'uid' => 1]);
    $worker->processItem(['nid' => $node->id(), 'profile_id' => 'gone', 'uid' => 1]);

    $this->assertSame([], $this->container->get('entity_type.manager')
      ->getStorage('aip_factcheck_result')->loadMultiple());
    $this->assertSame([], $this->collected);
  }

}
