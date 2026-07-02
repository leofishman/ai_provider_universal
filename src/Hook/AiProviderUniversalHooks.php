<?php

namespace Drupal\ai_provider_universal\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for ai_provider_universal.
 */
class AiProviderUniversalHooks {

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public static function entityInsert(EntityInterface $entity): void {
    self::clearProviderCache($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public static function entityUpdate(EntityInterface $entity): void {
    self::clearProviderCache($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public static function entityDelete(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === 'universal_server') {
      // Clean up associated model entities.
      $model_storage = \Drupal::entityTypeManager()->getStorage('universal_model');
      $models = $model_storage->loadByProperties(['server_id' => $entity->id()]);
      foreach ($models as $model) {
        $model->delete();
      }
    }
    self::clearProviderCache($entity);
  }

  /**
   * Clears AI provider plugin discovery when server entities change.
   */
  private static function clearProviderCache(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'universal_server') {
      return;
    }
    \Drupal::service('ai.provider')->clearCachedDefinitions();
  }

}
