<?php

namespace Drupal\ai_provider_universal_factcheck\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for ai_provider_universal_factcheck.
 */
class AiProviderUniversalFactcheckHooks {

  /**
   * Implements hook_mail().
   *
   * Builds subject/body for AdminNotifier deliveries. Keys today:
   * - scan_run
   * - settings_changed
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    $message['subject'] = (string) ($params['subject'] ?? '');
    $message['body'][] = (string) ($params['body'] ?? '');
  }

}
