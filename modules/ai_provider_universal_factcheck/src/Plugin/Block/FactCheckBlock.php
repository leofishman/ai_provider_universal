<?php

namespace Drupal\ai_provider_universal_factcheck\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_universal_factcheck\Form\StandaloneFactCheckForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exposes the standalone fact check form as a placeable block.
 */
#[Block(
  id: 'ai_provider_universal_factcheck_standalone',
  admin_label: new TranslatableMarkup('Fact check'),
  category: new TranslatableMarkup('AI'),
)]
class FactCheckBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The form builder.
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->formBuilder = $container->get('form_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return $this->formBuilder->getForm(StandaloneFactCheckForm::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'use standalone fact check');
  }

}
