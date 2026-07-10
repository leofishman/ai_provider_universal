<?php

namespace Drupal\ai_provider_universal_factcheck;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists scan profile config entities.
 */
class ScanProfileListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Profile');
    $header['bundles'] = $this->t('Bundles');
    $header['checks'] = $this->t('Enabled checks');
    $header['event_on'] = $this->t('Event');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ai_provider_universal_factcheck\Entity\ScanProfileInterface $entity */
    $enabled = array_keys(array_filter(
      $entity->getChecks(),
      static fn (array $check): bool => !empty($check['enabled']),
    ));
    $row['label'] = $entity->label();
    $row['bundles'] = implode(', ', $entity->getBundles());
    $row['checks'] = $enabled ? implode(', ', $enabled) : $this->t('none');
    $row['event_on'] = $entity->getEventOn();
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

}
