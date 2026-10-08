<?php

/**
 * @file
 * Post update functions for the terms submodule.
 */

/**
 * Limit the analyze API to 30 requests per user (or IP) per hour.
 */
function ai_provider_universal_terms_post_update_requests_per_hour(): void {
  $config = \Drupal::configFactory()->getEditable('ai_provider_universal_terms.settings');
  if ($config->get('requests_per_hour') === NULL) {
    $config->set('requests_per_hour', 30)->save();
  }
}
