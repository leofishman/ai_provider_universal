<?php

namespace Drupal\ai_provider_universal_terms\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Terms analyzer settings: model, chunking, limits.
 */
class TermsSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_provider_universal_terms_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_provider_universal_terms.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $model_options = [];
    foreach ($this->entityTypeManager->getStorage('ai_universal_model')->loadMultiple() as $model) {
      if (in_array('chat', $model->getEffectiveOperationTypes(), TRUE)) {
        $model_options[$model->id()] = $model->label();
      }
    }
    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Analyzer model'),
      '#description' => $this->t('Measured with reasoning off and temperature 0: set both on the model entity. On llama.cpp, Qwen needs <code>chat_template_kwargs.enable_thinking: false</code> in its extra request parameters.'),
      '#options' => ['' => $this->t('- None (the API answers 503) -')] + $model_options,
      '#config_target' => 'ai_provider_universal_terms.settings:model',
    ];
    $form['chunk_words'] = [
      '#type' => 'number',
      '#title' => $this->t('Piece size, in words'),
      '#description' => $this->t('Long documents are analyzed in pieces of about this many words, cut between paragraphs; one model call each. 0 sends the whole document in one call, which needs a model with a large context.'),
      '#min' => 0,
      '#config_target' => 'ai_provider_universal_terms.settings:chunk_words',
    ];
    $form['max_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Longest document, in characters'),
      '#description' => $this->t('Longer documents are refused (HTTP 413).'),
      '#min' => 1000,
      '#config_target' => 'ai_provider_universal_terms.settings:max_chars',
    ];
    $form['requests_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Requests per hour'),
      '#description' => $this->t('Per user, or per IP for anonymous callers; above it the API answers 429. 0 = no limit. Every request that passes validation counts.'),
      '#min' => 0,
      '#config_target' => 'ai_provider_universal_terms.settings:requests_per_hour',
    ];
    return parent::buildForm($form, $form_state);
  }

}
