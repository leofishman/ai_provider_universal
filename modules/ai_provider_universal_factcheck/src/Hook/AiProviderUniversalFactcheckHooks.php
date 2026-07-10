<?php

namespace Drupal\ai_provider_universal_factcheck\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\ai_provider_universal_factcheck\Service\ScanScheduler;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for ai_provider_universal_factcheck.
 */
class AiProviderUniversalFactcheckHooks {

  public function __construct(
    protected readonly ScanScheduler $scanScheduler,
  ) {
  }

  /**
   * Implements hook_mail().
   *
   * Builds subject/body for AdminNotifier deliveries. Keys today:
   * scan_run and settings_changed.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    $message['subject'] = (string) ($params['subject'] ?? '');
    $message['body'][] = (string) ($params['body'] ?? '');
  }

  /**
   * Implements hook_node_insert().
   *
   * Cheap filters + enqueue only; scans run on cron. Never blocks the save.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->scanScheduler->onNodeSave($node, 'insert');
  }

  /**
   * Implements hook_node_update().
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->scanScheduler->onNodeSave($node, 'update');
  }

}
