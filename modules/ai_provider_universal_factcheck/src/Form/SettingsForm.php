<?php

namespace Drupal\ai_provider_universal_factcheck\Form;

use Drupal\ai_provider_universal\Utility\PromptPlaceholders;
use Drupal\ai_provider_universal_factcheck\Event\FactcheckNotificationEvent;
use Drupal\ai_provider_universal_factcheck\Service\AdminNotifier;
use Drupal\ai_provider_universal_factcheck\Service\AiDetector;
use Drupal\ai_provider_universal_factcheck\Service\FactChecker;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
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
   * Admin email notifier.
   */
  protected AdminNotifier $adminNotifier;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->moduleHandler = $container->get('module_handler');
    $instance->adminNotifier = $container->get(AdminNotifier::class);
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

    // Include Smart Routes (virtual "Auto: ..." models) so they can be used
    // as checker/extractor/detector. They appear in the main AI settings.
    if ($this->moduleHandler->moduleExists('ai_provider_universal_router')) {
      $route_storage = $this->entityTypeManager->getStorage('ai_universal_route');
      foreach ($route_storage->loadMultiple() as $route) {
        if ($route->getOperationType() === 'chat') {
          $rid = 'route__' . $route->id();
          $model_options[$rid] = $this->t('Auto: @label', ['@label' => $route->label()]);
        }
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
      '#config_target' => 'ai_provider_universal_factcheck.settings:profile',
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
      '#options' => ['none' => $this->t('- Disabled -')] + $model_options,
      '#empty_option' => $this->t('- Same as checker -'),
      '#default_value' => $config->get('detector_model'),
    ];

    if ($this->moduleHandler->moduleExists('key')) {
      $form['keys'] = [
        '#type' => 'container',
        '#prefix' => '<div id="factcheck-key-selects">',
        '#suffix' => '</div>',
      ];
      $form['keys']['tavily_key'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Web evidence API key (Tavily)'),
        '#key_description' => FALSE,
        '#description' => $this->t('Key entity holding a <a href=":url" target="_blank">Tavily</a> API key. When the evidence index has nothing for a claim, the web is searched — restricted by your <em>Trusted site</em> nodes: positive-reputation domains are preferred, negative ones excluded. Leave empty to keep verification local-only.', [':url' => 'https://tavily.com']),
        '#empty_option' => $this->t('- Disabled -'),
        '#default_value' => $config->get('tavily_key'),
      ];
      $form['keys']['mbfc_key'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Media bias ratings API key (MBFC)'),
        '#key_description' => FALSE,
        '#description' => $this->t('Key entity holding a RapidAPI key for the <a href=":url" target="_blank">Media Bias Fact Check Ratings API</a>. Used by <code>drush factcheck:sync-bias-ratings --fetch=domain,…</code> to pull live bias/factual ratings into Trusted sites. Leave empty to import from static JSON only.', [':url' => 'https://rapidapi.com/mbfcnews/api/media-bias-fact-check-ratings-api2']),
        '#empty_option' => $this->t('- Disabled -'),
        '#default_value' => $config->get('mbfc_key'),
      ];
      $form['keys']['plagiarism_key'] = [
        '#type' => 'key_select',
        '#title' => $this->t('Plagiarism search API key'),
        '#key_description' => FALSE,
        '#description' => $this->t('Key entity holding a <a href=":url" target="_blank">Serper.dev</a> API key. When set, the content scan searches the web for verbatim copies of the longest sentences. Leave empty to disable.', [':url' => 'https://serper.dev']),
        '#empty_option' => $this->t('- Disabled -'),
        '#default_value' => $config->get('plagiarism_key'),
      ];
      $form['keys']['help'] = [
        '#markup' => '<p>' . $this->t('Missing a key? <a href=":url" target="_blank">Create one in a new tab</a>, then press %refresh.', [
          ':url' => Url::fromRoute('entity.key.add_form')->toString(),
          '%refresh' => $this->t('Refresh keys'),
        ]) . '</p>',
      ];
      $form['keys']['refresh_keys'] = [
        '#type' => 'button',
        '#name' => 'refresh_keys',
        '#value' => $this->t('Refresh keys'),
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::refreshKeysAjax',
          'wrapper' => 'factcheck-key-selects',
        ],
      ];
    }

    $form['max_claims'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum claims per answer'),
      '#description' => $this->t('Upper bound on claims extracted per answer. In the thorough profile each claim costs one checker call; fast/balanced batch them into one.'),
      '#default_value' => $config->get('max_claims') ?: 5,
      '#min' => 1,
      '#max' => 20,
      '#config_target' => 'ai_provider_universal_factcheck.settings:max_claims',
    ];

    $form['notify_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Notification email'),
      '#description' => $this->t('Optional default email when a content scan runs or these settings change. Leave empty to disable mail (the FactcheckNotificationEvent still fires for ECA/other listeners). Delivery uses the site mail plugin (SMTP, Symfony Mailer, …).'),
      '#default_value' => $config->get('notify_email'),
      '#config_target' => 'ai_provider_universal_factcheck.settings:notify_email',
    ];

    $flood_limits = (array) $config->get('scan_flood_limits');
    $form['scan_flood'] = [
      '#type' => 'details',
      '#title' => $this->t('Content scan rate limiting'),
      '#open' => (bool) array_filter($flood_limits),
    ];
    $form['scan_flood']['scan_flood_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Flood window (seconds)'),
      '#default_value' => $config->get('scan_flood_window') ?: 3600,
      '#min' => 60,
      '#config_target' => 'ai_provider_universal_factcheck.settings:scan_flood_window',
    ];
    $form['scan_flood']['limits'] = [
      '#type' => 'table',
      '#header' => [$this->t('Role'), $this->t('Max scans per window')],
      '#empty' => $this->t('No roles found.'),
    ];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      $rid = $role->id();
      $form['scan_flood']['limits'][$rid]['label'] = ['#markup' => $role->label()];
      $form['scan_flood']['limits'][$rid]['limit'] = [
        '#type' => 'number',
        '#title' => $this->t('Max scans per window for @role', ['@role' => $role->label()]),
        '#title_display' => 'invisible',
        '#default_value' => $flood_limits[$rid] ?? 0,
        '#min' => 0,
        '#description' => $this->t('0 = unlimited.'),
      ];
    }

    $saved_prompts = (array) $config->get('prompts');
    $form['prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('Prompts'),
      '#open' => (bool) array_filter($saved_prompts),
      '#description' => $this->t('Override the LLM prompt templates. Leave a field empty to use the shipped default (shown greyed out). Runtime values are substituted into the sprintf tokens (@tokens), which must be kept in the same order as the default.', ['@tokens' => '%s, %d']),
      '#tree' => TRUE,
    ];
    foreach ($this->promptDefinitions() as $key => $definition) {
      $tokens = PromptPlaceholders::describe($definition['default']);
      $form['prompts'][$key] = [
        '#type' => 'textarea',
        '#title' => $definition['title'],
        '#description' => $tokens === ''
          ? $definition['description']
          : $this->t('@description Required tokens, in order: @tokens.', [
            '@description' => $definition['description'],
            '@tokens' => $tokens,
          ]),
        '#default_value' => $saved_prompts[$key] ?? '',
        '#attributes' => ['placeholder' => $definition['default']],
        '#rows' => 5,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * The overridable prompts: shipped default and UI texts per key.
   *
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, description: \Drupal\Core\StringTranslation\TranslatableMarkup, default: string}>
   *   Keyed by the config key under "prompts".
   */
  protected function promptDefinitions(): array {
    return [
      'extract' => [
        'title' => $this->t('Claim extraction'),
        'description' => $this->t('Tokens: max claims (number), the text.'),
        'default' => FactChecker::EXTRACT_PROMPT,
      ],
      'verify' => [
        'title' => $this->t('Per-claim verdict'),
        'description' => $this->t('Tokens: evidence block, evidence suffix, the claim. The model must answer SUPPORTED / CONTRADICTED / UNSUPPORTED.'),
        'default' => FactChecker::VERIFY_PROMPT,
      ],
      'batch_verify' => [
        'title' => $this->t('Batched verdicts'),
        'description' => $this->t('Tokens: evidence suffix, numbered claim blocks. The model must answer with the JSON verdict array.'),
        'default' => FactChecker::BATCH_VERIFY_PROMPT,
      ],
      'taint' => [
        'title' => $this->t('Distrusted-site echo check'),
        'description' => $this->t('Tokens: numbered claims, distrusted evidence. The model must answer with a JSON array of claim numbers.'),
        'default' => FactChecker::TAINT_PROMPT,
      ],
      'analyze' => [
        'title' => $this->t('Discrepancy analysis'),
        'description' => $this->t('Tokens: the claim, annotated evidence.'),
        'default' => FactChecker::ANALYZE_PROMPT,
      ],
      'detect' => [
        'title' => $this->t('AI-likelihood detector'),
        'description' => $this->t('Token: the text. The model must answer with the JSON score object.'),
        'default' => AiDetector::DETECT_PROMPT,
      ],
    ];
  }

  /**
   * AJAX callback: re-renders the key selects with freshly created keys.
   */
  public function refreshKeysAjax(array &$form, FormStateInterface $form_state): array {
    return $form['keys'];
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

    foreach ($this->promptDefinitions() as $key => $definition) {
      $custom = trim((string) $form_state->getValue(['prompts', $key], ''));
      if ($custom !== '' && !PromptPlaceholders::matches($definition['default'], $custom)) {
        $form_state->setErrorByName("prompts][$key", $this->t('The @title prompt must keep the default sprintf tokens in the same order: @tokens.', [
          '@title' => $definition['title'],
          '@tokens' => PromptPlaceholders::describe($definition['default']) ?: $this->t('none'),
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // #config_target fields (profile, max_claims) are synced automatically;
    // we still manage the dynamic model/key selects manually.
    $this->config('ai_provider_universal_factcheck.settings')
      ->set('checker_model', $form_state->getValue('checker_model'))
      ->set('extractor_model', $form_state->getValue('extractor_model') ?? '')
      ->set('evidence_index', $form_state->getValue('evidence_index') ?? '')
      ->set('detector_model', $form_state->getValue('detector_model') ?? '')
      ->set('plagiarism_key', $form_state->getValue('plagiarism_key') ?? '')
      ->set('tavily_key', $form_state->getValue('tavily_key') ?? '')
      ->set('mbfc_key', $form_state->getValue('mbfc_key') ?? '')
      ->set('scan_flood_limits', array_filter(array_map(
        static fn (array $row): int => (int) $row['limit'],
        $form_state->getValue(['scan_flood', 'limits']) ?? []
      )))
      // Prompts are plain text sent to the LLM, never rendered as markup:
      // trim and drop empties so unset fields fall back to the shipped
      // defaults. Placeholder order is enforced in validateForm().
      ->set('prompts', array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $form_state->getValue('prompts', []),
      )))
      ->save();
    $this->adminNotifier->notify(
      FactcheckNotificationEvent::KEY_SETTINGS_CHANGED,
      (string) $this->t('[Fact check] Settings changed'),
      (string) $this->t('@name changed the fact check settings at @url.', [
        '@name' => $this->currentUser()->getAccountName(),
        '@url' => Url::fromRoute('ai_provider_universal_factcheck.settings')->setAbsolute()->toString(),
      ]),
      [
        'uid' => (int) $this->currentUser()->id(),
        'account_name' => $this->currentUser()->getAccountName(),
      ],
    );
    parent::submitForm($form, $form_state);
  }

}
