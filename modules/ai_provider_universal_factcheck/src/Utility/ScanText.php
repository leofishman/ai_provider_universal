<?php

namespace Drupal\ai_provider_universal_factcheck\Utility;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Extracts scannable plain text from an entity's body-like fields.
 *
 * Same field selection as the Content scan tab: formatted/long text only.
 * The title is deliberately excluded — as scannable text it shows up as a
 * bogus "claim" of its own.
 */
final class ScanText {

  /**
   * Field types whose values are worth scanning.
   */
  private const TEXT_FIELD_TYPES = ['text', 'text_long', 'text_with_summary', 'string_long'];

  /**
   * Returns the entity's scannable text, whitespace-normalized.
   */
  public static function extract(FieldableEntityInterface $entity): string {
    $parts = [];
    foreach ($entity->getFields() as $field) {
      if (!in_array($field->getFieldDefinition()->getType(), self::TEXT_FIELD_TYPES, TRUE)) {
        continue;
      }
      foreach ($field as $item) {
        if (!empty($item->value)) {
          $parts[] = strip_tags((string) $item->value);
        }
        if (!empty($item->summary)) {
          $parts[] = strip_tags((string) $item->summary);
        }
      }
    }
    return trim(preg_replace('/\s+/', ' ', implode('. ', array_filter($parts))));
  }

}
