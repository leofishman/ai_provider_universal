<?php

namespace Drupal\ai_provider_universal_router\Hook;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for ai_provider_universal_router.
 */
class AiProviderUniversalRouterHooks {

  use StringTranslationTrait;

  public function __construct(
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
    $this->clearProviderCache($entity);
  }

  /**
   * Clears the AI provider cache when Smart Routes change.
   *
   * New/updated routes then appear immediately in model dropdowns (including
   * Factcheck settings) without requiring a full cache rebuild from users.
   */
  protected function clearProviderCache(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === 'ai_universal_route') {
      $this->aiProviderManager->clearCachedDefinitions();
    }
  }

  /**
   * Implements hook_views_data().
   *
   * Exposes the routing decision log table to Views.
   * This enables nice admin/demo pages and JSON exports showing exactly
   * which model was chosen (local vs remote), complexity, estimated
   * tokens/cost and savings. Very useful for debugging, dashboards and
   * demonstrating token-efficient routing.
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $data = [];

    $data['ai_universal_router_log'] = [
      'table' => [
        'group' => $this->t('AI Router Log'),
        'provider' => 'ai_provider_universal_router',
        'base' => [
          'field' => 'id',
          'title' => $this->t('AI Router Log'),
          'help' => $this->t('One row per smart routing decision. Shows local vs remote model selection, complexity, token estimates and cost savings.'),
        ],
      ],
      'id' => [
        'title' => $this->t('ID'),
        'help' => $this->t('Unique identifier for the log entry.'),
        'field' => [
          'id' => 'numeric',
        ],
        'sort' => [
          'id' => 'standard',
        ],
        'filter' => [
          'id' => 'numeric',
        ],
      ],
      'timestamp' => [
        'title' => $this->t('Timestamp'),
        'help' => $this->t('When the routing decision happened.'),
        'field' => [
          'id' => 'date',
          'click sortable' => TRUE,
        ],
        'sort' => [
          'id' => 'date',
        ],
        'filter' => [
          'id' => 'date',
        ],
      ],
      'route_id' => [
        'title' => $this->t('Route'),
        'help' => $this->t('The smart route configuration that made the decision.'),
        'field' => [
          'id' => 'standard',
        ],
        'filter' => [
          'id' => 'string',
        ],
      ],
      'operation_type' => [
        'title' => $this->t('Operation type'),
        'help' => $this->t('The AI operation (chat, embeddings, etc.).'),
        'field' => [
          'id' => 'standard',
        ],
        'filter' => [
          'id' => 'string',
        ],
      ],
      'complexity' => [
        'title' => $this->t('Complexity'),
        'help' => $this->t('Simple, complex or escalated.'),
        'field' => [
          'id' => 'standard',
        ],
        'filter' => [
          'id' => 'string',
        ],
      ],
      'est_tokens' => [
        'title' => $this->t('Estimated tokens'),
        'help' => $this->t('Rough token count used for the decision.'),
        'field' => [
          'id' => 'numeric',
        ],
        'sort' => [
          'id' => 'standard',
        ],
      ],
      'chosen_model' => [
        'title' => $this->t('Chosen model'),
        'help' => $this->t('The actual model ID that was selected (can be local or remote).'),
        'field' => [
          'id' => 'standard',
        ],
        'filter' => [
          'id' => 'string',
        ],
      ],
      'candidates' => [
        'title' => $this->t('Candidates evaluated'),
        'help' => $this->t('How many models were considered for this decision.'),
        'field' => [
          'id' => 'numeric',
        ],
      ],
      'est_cost' => [
        'title' => $this->t('Est. cost (chosen)'),
        'help' => $this->t('Estimated cost of the model that was actually used.'),
        'field' => [
          'id' => 'numeric',
        ],
        'sort' => [
          'id' => 'standard',
        ],
      ],
      'est_cost_worst' => [
        'title' => $this->t('Est. cost (worst case)'),
        'help' => $this->t('Estimated cost if the most expensive eligible candidate had been chosen.'),
        'field' => [
          'id' => 'numeric',
        ],
      ],
    ];

    return $data;
  }

}
