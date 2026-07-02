<?php

namespace Drupal\ai_provider_universal_factcheck\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Fact check settings: checker model, evidence index, claim budget.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_provider_universal_factcheck_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_provider_universal_factcheck.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_provider_universal_factcheck.settings');
    $entityTypeManager = \Drupal::entityTypeManager();

    $model_options = [];
    /** @var \Drupal\ai_provider_universal\Entity\UniversalModelInterface $model */
    foreach ($entityTypeManager->getStorage('universal_model')->loadMultiple() as $model) {
      if (in_array('chat', $model->getEffectiveOperationTypes(), TRUE)) {
        $model_options[$model->id()] = $model->label();
      }
    }

    $form['checker_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Checker model'),
      '#description' => $this->t('Model used to extract and verify claims. A small fast local model is usually enough; it only judges, it does not generate content.'),
      '#options' => $model_options,
      '#empty_option' => $this->t('- Disabled -'),
      '#default_value' => $config->get('checker_model'),
    ];

    $index_options = [];
    if (\Drupal::moduleHandler()->moduleExists('search_api')) {
      foreach ($entityTypeManager->getStorage('search_api_index')->loadMultiple() as $index) {
        $index_options[$index->id()] = $index->label();
      }
    }

    $form['evidence_index'] = [
      '#type' => 'select',
      '#title' => $this->t('Evidence index (RAG grounding)'),
      '#description' => $this->t('Optional Search API index (e.g. an AI Search vector index) to retrieve evidence per claim. When set, claims are verified against your content; when empty, the checker model judges from its own knowledge.'),
      '#options' => $index_options,
      '#empty_option' => $this->t('- None (model-only verification) -'),
      '#default_value' => $config->get('evidence_index'),
      '#access' => (bool) $index_options,
    ];

    $form['max_claims'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum claims per answer'),
      '#description' => $this->t('Each claim costs one checker call (plus one extraction call per answer). Keep low to stay token-efficient.'),
      '#default_value' => $config->get('max_claims') ?: 5,
      '#min' => 1,
      '#max' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ai_provider_universal_factcheck.settings')
      ->set('checker_model', $form_state->getValue('checker_model'))
      ->set('evidence_index', $form_state->getValue('evidence_index') ?? '')
      ->set('max_claims', (int) $form_state->getValue('max_claims'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
