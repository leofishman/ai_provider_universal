<?php

namespace Drupal\ai_provider_universal\Hook;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for ai_provider_universal.
 */
class AiProviderUniversalHooks {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiProviderPluginManager $aiProviderManager,
  ) {}

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->clearProviderCache($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->clearProviderCache($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === 'universal_server') {
      // Clean up associated model entities.
      $model_storage = $this->entityTypeManager->getStorage('universal_model');
      $models = $model_storage->loadByProperties(['server_id' => $entity->id()]);
      foreach ($models as $model) {
        $model->delete();
      }
    }
    $this->clearProviderCache($entity);
  }

  /**
   * Clears AI provider plugin discovery when server entities change.
   */
  protected function clearProviderCache(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'universal_server') {
      return;
    }
    $this->aiProviderManager->clearCachedDefinitions();
  }

}
