<?php

namespace Drupal\ai_provider_universal_router;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * List builder for smart routes.
 */
class AiUniversalRouteListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Route'),
      'operation_type' => $this->t('Operation'),
      'candidates' => $this->t('Candidates'),
      'tiers' => $this->t('Tier (simple / complex)'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ai_provider_universal_router\Entity\AiUniversalRouteInterface $entity */
    $candidates = $entity->getCandidates();
    return [
      'label' => $entity->label(),
      'operation_type' => $entity->getOperationType(),
      'candidates' => $candidates ? count($candidates) : $this->t('All capable models'),
      'tiers' => $entity->getSimpleTier() . ' / ' . $entity->getComplexTier(),
    ] + parent::buildRow($entity);
  }

}
