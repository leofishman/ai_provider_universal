<?php

namespace Drupal\ai_provider_universal_factcheck\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fact check settings: checker model, evidence index, claim budget.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->moduleHandler = $container->get('module_handler');
    return $instance;
  }

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

    $model_options = [];
    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model */
    foreach ($this->entityTypeManager->getStorage('ai_universal_model')->loadMultiple() as $model) {
      if (in_array('chat', $model->getEffectiveOperationTypes(), TRUE)) {
        $model_options[$model->id()] = $model->label();
      }
    }

    $form['profile'] = [
      '#type' => 'radios',
      '#title' => $this->t('Verification profile'),
      '#options' => [
        'fast' => $this->t('Fast — lowest cost and latency'),
        'balanced' => $this->t('Balanced — good verdicts at moderate cost (recommended)'),
        'thorough' => $this->t('Thorough — most curated verdicts, cost and time no object'),
      ],
      '#default_value' => $config->get('profile') ?: 'balanced',
    ];
    $form['profile']['fast']['#description'] = $this->t('One batched checker call for all claims, 2 evidence passages per claim, no distrusted-site check, no discrepancy analysis. Verdicts cached 6 hours.');
    $form['profile']['balanced']['#description'] = $this->t('Batched verdicts, 3 passages per claim, one answer-level distrusted-site check, discrepancy analysis on unsettled claims. Verdicts cached 1 hour.');
    $form['profile']['thorough']['#description'] = $this->t('Individual checker call per claim, 5 passages, per-claim distrusted-site checks, discrepancy analysis. No caching — every scan is fresh.');

    $form['checker_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Checker model'),
      '#description' => $this->t('Model that judges each claim. A small fast local model is usually enough. Specialized checkers like Bespoke-MiniCheck are auto-detected by name, but they require an evidence index and a separate extractor model.'),
      '#options' => $model_options,
      '#empty_option' => $this->t('- Disabled -'),
      '#default_value' => $config->get('checker_model'),
    ];

    $form['extractor_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Claim extractor model'),
      '#description' => $this->t('General chat model that splits the answer into atomic claims (JSON output). Leave empty to use the checker model — not valid when the checker is MiniCheck, which cannot extract.'),
      '#options' => $model_options,
      '#empty_option' => $this->t('- Same as checker -'),
      '#default_value' => $config->get('extractor_model'),
    ];

    $index_options = [];
    if ($this->moduleHandler->moduleExists('search_api')) {
      foreach ($this->entityTypeManager->getStorage('search_api_index')->loadMultiple() as $index) {
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

    $form['detector_model'] = [
      '#type' => 'select',
      '#title' => $this->t('AI-detection model'),
      '#description' => $this->t('Model that estimates how likely a scanned text is AI-generated (content scan tab on nodes). Heuristic LLM judgement, not a trained detector. Leave empty to use the checker model.'),
      '#options' => $model_options,
      '#empty_option' => $this->t('- Same as checker -'),
      '#default_value' => $config->get('detector_model'),
    ];

    $key_options = [];
    if ($this->moduleHandler->moduleExists('key')) {
      foreach ($this->entityTypeManager->getStorage('key')->loadMultiple() as $key) {
        $key_options[$key->id()] = $key->label();
      }
    }
    $form['tavily_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Web evidence API key (Tavily)'),
      '#description' => $this->t('Key entity holding a <a href=":url">Tavily</a> API key. When the evidence index has nothing for a claim, the web is searched — restricted by your <em>Trusted site</em> nodes: positive-reputation domains are preferred, negative ones excluded. Leave empty to keep verification local-only.', [':url' => 'https://tavily.com']),
      '#options' => $key_options,
      '#empty_option' => $this->t('- Disabled -'),
      '#default_value' => $config->get('tavily_key'),
      '#access' => (bool) $key_options,
    ];

    $form['plagiarism_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Plagiarism search API key'),
      '#description' => $this->t('Key entity holding a <a href=":url">Serper.dev</a> API key. When set, the content scan searches the web for verbatim copies of the longest sentences. Leave empty to disable.', [':url' => 'https://serper.dev']),
      '#options' => $key_options,
      '#empty_option' => $this->t('- Disabled -'),
      '#default_value' => $config->get('plagiarism_key'),
      '#access' => (bool) $key_options,
    ];

    $form['max_claims'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum claims per answer'),
      '#description' => $this->t('Upper bound on claims extracted per answer. In the thorough profile each claim costs one checker call; fast/balanced batch them into one.'),
      '#default_value' => $config->get('max_claims') ?: 5,
      '#min' => 1,
      '#max' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $checker = (string) $form_state->getValue('checker_model');
    if (str_contains(strtolower($checker), 'minicheck') && !$form_state->getValue('extractor_model')) {
      $form_state->setErrorByName('extractor_model', $this->t('MiniCheck cannot extract claims; pick a general chat model as extractor.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ai_provider_universal_factcheck.settings')
      ->set('profile', $form_state->getValue('profile'))
      ->set('checker_model', $form_state->getValue('checker_model'))
      ->set('extractor_model', $form_state->getValue('extractor_model') ?? '')
      ->set('evidence_index', $form_state->getValue('evidence_index') ?? '')
      ->set('max_claims', (int) $form_state->getValue('max_claims'))
      ->set('detector_model', $form_state->getValue('detector_model') ?? '')
      ->set('plagiarism_key', $form_state->getValue('plagiarism_key') ?? '')
      ->set('tavily_key', $form_state->getValue('tavily_key') ?? '')
      ->save();
    parent::submitForm($form, $form_state);
  }

}
