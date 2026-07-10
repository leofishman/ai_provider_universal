<?php

namespace Drupal\ai_provider_universal\Utility;

/**
 * Validates admin-edited prompt overrides against their shipped defaults.
 *
 * Prompts are sprintf() templates: an override must keep the exact
 * placeholder sequence (%s / %d, in order) of the default or the runtime
 * substitution breaks. Everything else about the wording is free.
 */
final class PromptPlaceholders {

  /**
   * Whether a custom prompt keeps the default's placeholder sequence.
   *
   * @param string $default
   *   The shipped sprintf template.
   * @param string $custom
   *   The admin-edited template.
   */
  public static function matches(string $default, string $custom): bool {
    return self::sequence($default) === self::sequence($custom);
  }

  /**
   * Human-readable placeholder list of a template, for form descriptions.
   *
   * @return string
   *   E.g. "%d, %s" — empty string when the template has no placeholders.
   */
  public static function describe(string $default): string {
    return implode(', ', self::sequence($default));
  }

  /**
   * Ordered sprintf placeholders of a template.
   *
   * @return string[]
   *   E.g. ['%d', '%s', '%s'].
   */
  protected static function sequence(string $template): array {
    preg_match_all('/%[sd]/', $template, $matches);
    return $matches[0];
  }

}
