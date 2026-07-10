<?php

namespace Drupal\ai_provider_universal\Utility;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Site\Settings;

/**
 * Site-editable model defaults from definitions/model_defaults.yml.
 *
 * Guesses a quality tier for well-known model families and (optionally)
 * costs for models whose backend publishes no pricing. Used at discovery
 * to prefill fields the backend left unset; like all detected metadata it
 * never overwrites a manual edit and remains fully overridable per model
 * in the UI.
 *
 * The module-shipped file is replaced on module updates. Site-specific
 * entries belong in an override file outside the module (same format,
 * entries win over the shipped ones), declared in settings.php:
 * @code
 * $settings['ai_provider_universal_model_defaults'] = 'sites/default/model_defaults.yml';
 * @endcode
 */
final class ModelDefaults {

  /**
   * Parsed model_defaults.yml, loaded once per request.
   */
  private static ?array $table = NULL;

  /**
   * Guesses a quality tier (1-5) from the raw model id, NULL when unknown.
   */
  public static function guessTier(string $raw_model_id): ?int {
    $tiers = self::table()['quality_tiers'] ?? [];
    foreach ($tiers['families'] ?? [] as $pattern => $tier) {
      if (preg_match('{' . $pattern . '}i', $raw_model_id)) {
        return (int) $tier;
      }
    }
    // Open-weight models usually carry their parameter count in the id
    // (8b, 70b, 0.5b, 235b): map size to tier.
    if (preg_match('/(\d+(?:\.\d+)?)\s*b\b/i', $raw_model_id, $m)) {
      $size = (float) $m[1];
      $sizes = $tiers['sizes'] ?? [];
      krsort($sizes);
      foreach ($sizes as $min => $tier) {
        if ($size >= (float) $min) {
          return (int) $tier;
        }
      }
    }
    return NULL;
  }

  /**
   * Guesses a quality tier from the output price, NULL when unpriced.
   *
   * Last-resort fallback for live-pricing catalogs whose model ids match
   * no family or size pattern: price correlates loosely with capability.
   *
   * @param float $cost_output
   *   Cost in USD per 1M output tokens.
   */
  public static function guessTierFromPrice(float $cost_output): ?int {
    $bands = self::table()['quality_tiers']['prices'] ?? [];
    // Keys are quoted in the YAML (floats are invalid mapping keys), so
    // sort numerically, not lexicographically.
    krsort($bands, SORT_NUMERIC);
    foreach ($bands as $min => $tier) {
      if ($cost_output >= (float) $min) {
        return (int) $tier;
      }
    }
    return NULL;
  }

  /**
   * Guesses costs (USD per 1M tokens) from the raw model id.
   *
   * @return array
   *   Any subset of 'cost_input' / 'cost_output'; empty when unknown.
   */
  public static function guessCosts(string $raw_model_id): array {
    foreach (self::table()['costs'] ?? [] as $pattern => $costs) {
      if (preg_match('{' . $pattern . '}i', $raw_model_id)) {
        return array_filter([
          'cost_input' => $costs['input'] ?? NULL,
          'cost_output' => $costs['output'] ?? NULL,
        ], static fn ($v) => $v !== NULL);
      }
    }
    return [];
  }

  /**
   * Guesses vendor-recommended sampling parameters from the raw model id.
   *
   * @return array
   *   Any subset of temperature / top_p / frequency_penalty /
   *   presence_penalty; empty when the family publishes no recommendation.
   */
  public static function guessSampling(string $raw_model_id): array {
    foreach (self::table()['sampling'] ?? [] as $pattern => $sampling) {
      if (is_array($sampling) && preg_match('{' . $pattern . '}i', $raw_model_id)) {
        return $sampling;
      }
    }
    return [];
  }

  /**
   * Loads and caches the YAML table, applying the site override file.
   */
  private static function table(): array {
    if (self::$table !== NULL) {
      return self::$table;
    }
    $base = Yaml::decode((string) file_get_contents(
      dirname(__DIR__, 2) . '/definitions/model_defaults.yml',
    )) ?: [];

    try {
      $override_path = Settings::get('ai_provider_universal_model_defaults');
    }
    catch (\Throwable) {
      // Settings not initialized (unit tests): shipped defaults only.
      $override_path = NULL;
    }
    if (is_string($override_path) && is_readable($override_path)) {
      $override = Yaml::decode((string) file_get_contents($override_path)) ?: [];
      // First match wins, so prepending makes override entries take
      // precedence while shipped defaults remain as fallback.
      foreach (['families', 'sizes', 'prices'] as $key) {
        $base['quality_tiers'][$key] = ($override['quality_tiers'][$key] ?? [])
          + ($base['quality_tiers'][$key] ?? []);
      }
      $base['costs'] = ($override['costs'] ?? []) + ($base['costs'] ?? []);
      $base['sampling'] = ($override['sampling'] ?? []) + ($base['sampling'] ?? []);
    }
    return self::$table = $base;
  }

}
