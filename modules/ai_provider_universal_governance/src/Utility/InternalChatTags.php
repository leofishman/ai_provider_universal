<?php

namespace Drupal\ai_provider_universal_governance\Utility;

/**
 * Tags that mark tool/internal chat calls (not end-user generation).
 *
 * Default Guardrail attach and content provenance skip these: factcheck
 * extract/detect, the route complexity classifier and the route verifier
 * must not inherit a site's "chatbot" Guardrail set or look like published
 * AI-origin content under Art. 50. Callers pass these as the $tags argument
 * to chat(); AI core also prepends the operation type (e.g. "chat").
 */
final class InternalChatTags {

  /**
   * Factcheck submodule tool calls (extractor, checker, detector).
   */
  public const FACTCHECK = 'ai_provider_universal_factcheck';

  /**
   * Smart-router complexity classifier.
   */
  public const COMPLEXITY_CLASSIFIER = 'complexity_classifier';

  /**
   * Smart-route lightweight answer verifier.
   */
  public const ROUTE_VERIFIER = 'route_verifier';

  /**
   * All known internal tool tags.
   *
   * @var string[]
   */
  public const ALL = [
    self::FACTCHECK,
    self::COMPLEXITY_CLASSIFIER,
    self::ROUTE_VERIFIER,
  ];

  /**
   * Whether any tag marks this call as an internal tool invocation.
   *
   * @param string[] $tags
   *   Tags from the chat call or PreGenerate/PostCall event (list form).
   */
  public static function isInternal(array $tags): bool {
    foreach (self::ALL as $internal) {
      if (in_array($internal, $tags, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
