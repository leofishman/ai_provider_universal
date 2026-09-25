<?php

namespace Drupal\ai_provider_universal\Utility;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Reads and writes AI core's per-model settings for our models.
 *
 * AI core keeps a model's settings (capabilities included) in
 * ai.settings:models, under [provider][operation_type][model_id]. Config
 * keys cannot contain a dot and every ai_universal_model id is
 * "<server>.<model>", so the key replaces dots with "__". Never decoded:
 * the raw id is always known when a lookup happens.
 *
 * See docs/model-capabilities.md.
 */
final class CoreModelConfig {

  /**
   * The config key AI core's settings use for a model id.
   */
  public static function key(string $model_id): string {
    return str_replace('.', '__', $model_id);
  }

  /**
   * The stored settings for a model, empty when none were saved.
   */
  public static function read(ConfigFactoryInterface $config_factory, string $operation_type, string $model_id): array {
    return $config_factory->get('ai.settings')->get(self::path($operation_type, $model_id)) ?? [];
  }

  /**
   * Stores settings for a model, replacing any saved before.
   */
  public static function write(ConfigFactoryInterface $config_factory, string $operation_type, string $model_id, array $values): void {
    $config_factory->getEditable('ai.settings')
      ->set(self::path($operation_type, $model_id), ['model_id' => self::key($model_id)] + $values)
      ->save();
  }

  /**
   * The ai.settings path of one model's entry.
   */
  private static function path(string $operation_type, string $model_id): string {
    return 'models.universal.' . $operation_type . '.' . self::key($model_id);
  }

}
