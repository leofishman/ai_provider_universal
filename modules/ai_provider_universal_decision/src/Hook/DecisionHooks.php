<?php

namespace Drupal\ai_provider_universal_decision\Hook;

use Drupal\ai_provider_universal_decision\DecisionUniversalProvider;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for ai_provider_universal_decision.
 */
class DecisionHooks {

  /**
   * Implements hook_ai_provider_info_alter().
   *
   * AI core only routes the Decision operation to a provider class that
   * implements DecisionInterface, which ships from AI 1.6; the base provider
   * supports older versions, so the subclass is swapped in from here.
   */
  #[Hook('ai_provider_info_alter')]
  public function aiProviderInfoAlter(array &$definitions): void {
    if (isset($definitions['universal'])) {
      $definitions['universal']['class'] = DecisionUniversalProvider::class;
    }
  }

}
