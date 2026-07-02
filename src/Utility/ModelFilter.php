<?php

namespace Drupal\ai_provider_universal\Utility;

/**
 * Matches model IDs against comma-separated glob filter patterns.
 */
final class ModelFilter {

  /**
   * Checks if a model ID matches a comma-separated filter pattern.
   *
   * @param string $model_id
   *   The raw model ID.
   * @param string $pattern_string
   *   Comma-separated list of allowed/denied models.
   *
   * @return bool
   *   TRUE if the model ID matches the filter, FALSE otherwise.
   */
  public static function matches(string $model_id, string $pattern_string): bool {
    $pattern_string = trim($pattern_string);
    if ($pattern_string === '') {
      return TRUE;
    }

    $patterns = array_map('trim', explode(',', $pattern_string));
    $patterns = array_filter($patterns);
    if (empty($patterns)) {
      return TRUE;
    }

    $include_patterns = [];
    $exclude_patterns = [];

    foreach ($patterns as $pattern) {
      if (str_starts_with($pattern, '!')) {
        $exclude_patterns[] = substr($pattern, 1);
      }
      else {
        $include_patterns[] = $pattern;
      }
    }

    foreach ($exclude_patterns as $pattern) {
      if (self::matchGlob($model_id, $pattern)) {
        return FALSE;
      }
    }

    if (!empty($include_patterns)) {
      foreach ($include_patterns as $pattern) {
        if (self::matchGlob($model_id, $pattern)) {
          return TRUE;
        }
      }
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Matches a string against a simple glob pattern (supporting wildcard *).
   *
   * @param string $string
   *   The string to match.
   * @param string $pattern
   *   The glob pattern.
   *
   * @return bool
   *   TRUE if matches, FALSE otherwise.
   */
  public static function matchGlob(string $string, string $pattern): bool {
    $quoted = preg_quote($pattern, '/');
    $regex = str_replace('\*', '.*', $quoted);
    return (bool) preg_match('/^' . $regex . '$/i', $string);
  }

}
