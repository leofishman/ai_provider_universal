<?php

namespace Drupal\ai_provider_universal_factcheck\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Viewing a fact check result needs its own permission; changing it is admin.
 *
 * Results are written by the scan code itself, never through a form, so
 * create, update and delete stay with the admin permission.
 */
class FactcheckResultAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermission($account, 'view factcheck results');
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
