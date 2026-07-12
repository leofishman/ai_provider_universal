<?php

namespace Drupal\ai_provider_universal_router\Form;

use Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider;
use Drupal\ai_provider_universal\Utility\PromptPlaceholders;
use Drupal\ai_provider_universal_router\Service\ComplexityClassifier;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Smart routing settings: classifier model and prompt overrides.
 */
class RouterSettingsForm extends ConfigFormBase {

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
    return 'ai_provider_universal_router_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_provider_universal_router.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_provider_universal_router.settings');

    $model_options = [];
    foreach ($this->entityTypeManager->getStorage('ai_universal_model')->loadMultiple() as $model) {
      if (in_array('chat', $model->getEffectiveOperationTypes(), TRUE)) {
        $model_options[$model->id()] = $model->label();
      }
    }
    $form['classifier_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Complexity classifier model'),
      '#description' => $this->t('Optional model that classifies prompts the heuristics consider simple (use a tiny, free local model). Leave disabled for heuristics only; any classifier failure falls back to heuristics.'),
      '#options' => ['' => $this->t('- Heuristics only -')] + $model_options,
      '#config_target' => 'ai_provider_universal_router.settings:classifier_model',
    ];

    $saved_prompts = (array) $config->get('prompts');
    $form['prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('Prompts'),
      '#open' => (bool) array_filter($saved_prompts),
      '#description' => $this->t('Override the routing prompt templates. Leave a field empty to use the shipped default (shown greyed out). Runtime values are substituted into the sprintf tokens, which must be kept in the same order.'),
      '#tree' => TRUE,
    ];
    $form['prompts']['classifier'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Classifier system prompt'),
      '#description' => $this->t('The model must answer with one word: simple or complex. No tokens.'),
      '#config_target' => 'ai_provider_universal_router.settings:prompts.classifier',
      '#attributes' => ['placeholder' => ComplexityClassifier::CLASSIFIER_PROMPT],
      '#rows' => 4,
    ];
    $form['prompts']['verifier'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Route verifier prompt'),
      '#description' => $this->t('Tokens, in order: the task, the answer. The model must answer yes or no.'),
      '#config_target' => 'ai_provider_universal_router.settings:prompts.verifier',
      '#attributes' => ['placeholder' => UniversalProvider::VERIFIER_PROMPT],
      '#rows' => 4,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $defaults = [
      'classifier' => ComplexityClassifier::CLASSIFIER_PROMPT,
      'verifier' => UniversalProvider::VERIFIER_PROMPT,
    ];
    foreach ($defaults as $key => $default) {
      // Trim so empty/whitespace falls back to shipped defaults at runtime.
      $custom = trim((string) $form_state->getValue(['prompts', $key], ''));
      $form_state->setValue(['prompts', $key], $custom);
      if ($custom !== '' && !PromptPlaceholders::matches($default, $custom)) {
        $form_state->setErrorByName("prompts][$key", $this->t('The prompt must keep the default sprintf tokens in the same order: @tokens.', [
          '@tokens' => PromptPlaceholders::describe($default) ?: $this->t('none'),
        ]));
      }
    }
  }

}
