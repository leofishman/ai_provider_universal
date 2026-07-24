<?php

namespace Drupal\ai_provider_universal\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ai_provider_universal\Backend\AiServerBackendManager;
use Drupal\ai_provider_universal\Service\ModelCatalog;
use Drupal\ai_provider_universal\Service\UsageTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Form for adding and editing ai_universal_server entities.
 */
class AiUniversalServerForm extends EntityForm {

  /**
   * Operation type labels for override checkboxes.
   */
  const OPERATION_TYPE_LABELS = [
    'chat'           => 'Chat',
    'embeddings'     => 'Embeddings',
    'speech_to_text' => 'Speech to Text',
    'rerank'         => 'Rerank',
    'moderation'     => 'Moderation',
    'text_to_image'  => 'Text to Image',
  ];

  /**
   * Constructs the form.
   */
  public function __construct(
    protected ModelCatalog $modelCatalog,
    protected AiServerBackendManager $backendManager,
    protected UsageTracker $usageTracker,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    // Assign to the (untyped) property inherited from EntityForm instead of
    // promoting a natively-typed override.
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container) {
    return new static(
      $container->get(ModelCatalog::class),
      $container->get(AiServerBackendManager::class),
      $container->get(UsageTracker::class),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server */
    $server = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server name'),
      '#description' => $this->t('A human-readable name for this server (e.g. "Ollama Local", "GPU Chat Server").'),
      '#maxlength' => 255,
      '#default_value' => $server->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $server->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_provider_universal\Entity\AiUniversalServer::load',
      ],
      '#disabled' => !$server->isNew(),
    ];

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection'),
      '#open' => TRUE,
    ];

    // Hosted backends carry a fixed default endpoint (OpenRouter, Hugging
    // Face, ...): host/port stay hidden for them via #states and each shows
    // its endpoint instead. The backend plugin is the source of truth — its
    // getBaseUri() on a hostless server reveals the default.
    $defaultUris = [];
    foreach (array_keys($this->backendManager->getDefinitions()) as $backend_id) {
      if ($uri = $this->getBackendDefaultUri($backend_id)) {
        $defaultUris[$backend_id] = $uri;
      }
    }
    // OR-list of #states value conditions for backends that need a host.
    $needsHost = array_map(
      static fn (string $id): array => ['value' => $id],
      array_values(array_diff(array_keys($this->backendManager->getDefinitions()), array_keys($defaultUris))),
    );

    $backendOptions = $this->backendManager->getOptions();
    $form['connection']['backend'] = [
      '#type' => 'select',
      '#title' => $this->t('Backend'),
      '#description' => $this->t('The protocol this server speaks. OpenAI-compatible covers llama.cpp, vLLM, LM Studio and any similar local or remote server. Dedicated backends improve model detection: Ollama (local), Groq, OpenRouter, Hugging Face, Fireworks, Ollama Cloud, LiteLLM/amazee.ai, Grok (xAI). Other modules can add native backends.'),
      '#options' => $backendOptions,
      '#default_value' => $server->getBackend(),
      '#required' => TRUE,
      '#access' => count($backendOptions) > 1,
    ];

    foreach ($defaultUris as $backend_id => $uri) {
      // A container (not 'item') so #states reliably hides the wrapper.
      $form['connection']['endpoint_hint_' . $backend_id] = [
        '#type' => 'container',
        '#states' => [
          'visible' => [':input[name="backend"]' => ['value' => $backend_id]],
        ],
        'text' => [
          '#markup' => $this->t('Endpoint: %uri (API key required; host and port are not needed).', ['%uri' => $uri]),
        ],
      ];
    }

    $form['connection']['host_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host Name'),
      '#description' => $this->t('The host name including protocol. Local examples: http://127.0.0.1 (llama.cpp, vLLM, LM Studio, LiteLLM), http://host.docker.internal (from DDEV/Docker). Remote example: https://api.openai.com.'),
      '#default_value' => $server->getHostName(),
      '#attributes' => ['placeholder' => 'http://127.0.0.1'],
      '#states' => [
        'visible' => [':input[name="backend"]' => $needsHost],
      ],
    ];

    $form['connection']['port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Port'),
      '#description' => $this->t('Port number. Leave empty for default. Common defaults: Ollama 11434, llama.cpp 8080, vLLM 8000, LM Studio 1234, LiteLLM 4000. Remote HTTPS APIs usually need no port (443).'),
      '#default_value' => $server->getPort(),
      '#attributes' => ['placeholder' => '11434'],
      '#states' => [
        'visible' => [':input[name="backend"]' => $needsHost],
      ],
    ];

    $form['connection']['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API Key'),
      '#key_description' => FALSE,
      '#description' => $this->t('Required for hosted services. Optional for local servers: leave empty when unauthenticated (llama.cpp, Ollama, LM Studio), set for vLLM or LiteLLM with an api-key configured. Missing key? <a href=":url" target="_blank">Create one in a new tab</a>, then press %refresh.', [
        ':url' => Url::fromRoute('entity.key.add_form')->toString(),
        '%refresh' => $this->t('Refresh keys'),
      ]),
      '#default_value' => $server->getApiKey(),
      '#prefix' => '<div id="aip-key-select">',
      '#suffix' => '</div>',
    ];

    $form['connection']['refresh_keys'] = [
      '#type' => 'button',
      '#name' => 'refresh_keys',
      '#value' => $this->t('Refresh keys'),
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::refreshKeysAjax',
        'wrapper' => 'aip-key-select',
      ],
    ];

    $form['connection']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout (seconds)'),
      '#description' => $this->t('Request timeout in seconds. Increase for slow models or large inputs.'),
      '#default_value' => $server->getTimeout() ?: 600,
      '#min' => 5,
      '#max' => 3600,
    ];

    $form['connection']['detect'] = [
      '#type' => 'button',
      '#value' => $this->t('Test connection & list models'),
      '#ajax' => [
        'callback' => '::detectModelsAjax',
        'wrapper' => 'aip-detect-results',
      ],
    ];

    $form['connection']['detect_results'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'aip-detect-results'],
    ];

    $usage = $server->isNew() ? NULL : $this->usageTracker->getTodayForServer((string) $server->id());
    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Usage limits'),
      '#description' => $usage === NULL
        ? $this->t('Daily limits for this server (all its models combined). Enforced by the Smart Router submodule: over-limit servers are skipped by routes and reject direct calls until the day rolls over.')
        : $this->t('Daily limits for this server (all its models combined). Enforced by the Smart Router submodule: over-limit servers are skipped by routes and reject direct calls until the day rolls over. <strong>Usage today: @requests requests, @tokens tokens.</strong>', [
          '@requests' => $usage['requests'],
          '@tokens' => $usage['input_tokens'] + $usage['output_tokens'],
        ]),
      '#open' => $server->getDailyRequestLimit() !== NULL || $server->getDailyTokenLimit() !== NULL,
    ];

    $form['limits']['daily_request_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily request limit'),
      '#description' => $this->t('Maximum requests per day. Leave empty for unlimited; 0 blocks the server until the day rolls over.'),
      '#default_value' => $server->getDailyRequestLimit(),
      '#min' => 0,
      '#step' => 1,
      '#parents' => ['daily_request_limit'],
    ];

    $form['limits']['daily_token_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily token limit'),
      '#description' => $this->t('Maximum tokens (input + output) per day. Leave empty for unlimited; 0 blocks the server until the day rolls over.'),
      '#default_value' => $server->getDailyTokenLimit(),
      '#min' => 0,
      '#step' => 1,
      '#parents' => ['daily_token_limit'],
    ];

    $form['limits']['alert_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Alert threshold (%)'),
      '#description' => $this->t('Log a warning and dispatch an event when usage reaches this percentage of a limit. Leave empty to disable alerts.'),
      '#default_value' => $server->getAlertThreshold(),
      '#min' => 1,
      '#max' => 100,
      '#step' => 1,
      '#parents' => ['alert_threshold'],
    ];

    $form['limits']['limit_grace'] = [
      '#type' => 'number',
      '#title' => $this->t('Limit grace (%)'),
      '#description' => $this->t('Allow usage to exceed the limits by up to this percentage before blocking. Leave empty or 0 to block exactly at the limit.'),
      '#default_value' => $server->getLimitGrace(),
      '#min' => 0,
      '#max' => 100,
      '#step' => 1,
      '#parents' => ['limit_grace'],
    ];

    $form['filtering'] = [
      '#type' => 'details',
      '#title' => $this->t('Model Filtering'),
      '#open' => TRUE,
    ];

    $form['filtering']['model_filter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model filter pattern'),
      '#description' => $this->t('Comma-separated list of allowed models (wildcards * supported). Examples: <code>llama3*, *mistral*, !*old*</code>. Leave empty to allow all models.'),
      '#default_value' => $server->getModelFilter(),
      '#attributes' => ['placeholder' => 'llama3*, *mistral*, !*old*'],
      '#parents' => ['model_filter'],
    ];

    // Operation types are controlled per-model (see "Model capability
    // overrides" below), not at the server level. The server entity keeps an
    // `operation_types` field in schema for potential future use, but it is not
    // exposed here to avoid two overlapping override mechanisms.
    //
    // Model capability overrides (edit only, when models are known).
    if (!$server->isNew()) {
      $form['overrides'] = $this->buildOverridesForm($server);
    }

    return $form;

  }

  /**
   * Returns a backend's default endpoint, '' when it needs an explicit host.
   *
   * The backend plugin is the source of truth: its getBaseUri() on a
   * hostless server yields the fixed service endpoint (OpenRouter, Hugging
   * Face, ...) or '' for backends that require a configured host.
   */
  protected function getBackendDefaultUri(string $backend_id): string {
    try {
      $backend = $this->backendManager->createInstance($backend_id);
      /** @var \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $blank */
      $blank = $this->entityTypeManager->getStorage('ai_universal_server')->create([
        'host_name' => '',
        'port' => '',
      ]);
      return $backend->getBaseUri($blank);
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Builds the model capability overrides fieldset.
   *
   * Now reads from ai_universal_model config entities (instead of State).
   */
  protected function buildOverridesForm($server): array {
    $server_id = $server->id();

    $element = [
      '#type'  => 'details',
      '#title' => $this->t('Models: capabilities and routing metadata'),
      '#description' => $this->t(
        'Capabilities are auto-detected from server metadata and HuggingFace; override them here when detection fails. Cost, quality tier and context length feed the smart router: it picks the cheapest model that satisfies a route.'
      ),
      '#open' => FALSE,
      // #tree must be TRUE so each model's checkbox values nest under the
      // 'overrides' parent, matching how saveModelOverrides() reads them
      // (getValue(['overrides', $model_id])). Without it FAPI defaults to
      // FALSE, the values land at the top level, and overrides never persist.
      '#tree' => TRUE,
    ];

    $model_storage = $this->entityTypeManager->getStorage('ai_universal_model');
    $models = $model_storage->loadByProperties(['server_id' => $server_id]);

    if (empty($models)) {
      $element['empty'] = [
        '#markup' => $this->t('<p>No models found yet. Save the server first, then models will be discovered automatically.</p>'),
      ];
      return $element;
    }

    $type_options = array_map([$this, 't'], self::OPERATION_TYPE_LABELS);

    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model */
    foreach ($models as $model) {
      $raw_id = $model->getRawModelId();
      $auto_types = $model->getDetectedOperationTypes() ?: ['chat'];
      $auto_label = implode(', ', $auto_types);
      $current_overrides = $model->getOperationTypes();

      // Use model entity id as form key (stable).
      $key = $model->id();

      $features = $model->getSupportedFeatures();
      $feature_label = $features ? implode(', ', $features) : $this->t('none reported');

      $element[$key] = [
        '#type'  => 'details',
        '#title' => $raw_id,
        '#open'  => FALSE,
      ];

      $element[$key]['supported_features'] = [
        '#markup' => $this->t('<p>Catalog features: <em>@features</em>.</p>', [
          '@features' => $feature_label,
        ]),
      ];

      $element[$key]['operation_types'] = [
        '#type'          => 'checkboxes',
        '#title'         => $this->t('Operation types'),
        '#description'   => $this->t('Auto-detected: <em>@types</em>. Leave unchecked to use auto-detection.', ['@types' => $auto_label]),
        '#options'       => $type_options,
        '#default_value' => $current_overrides,
      ];

      $element[$key]['cost_input'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Cost per 1M input tokens (USD)'),
        '#description'   => $this->t('Use 0 for local/self-hosted models. Leave empty if unknown.'),
        '#default_value' => $model->getCostInput(),
        '#min'           => 0,
        '#step'          => 'any',
      ];

      $element[$key]['cost_output'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Cost per 1M output tokens (USD)'),
        '#default_value' => $model->getCostOutput(),
        '#min'           => 0,
        '#step'          => 'any',
      ];

      $element[$key]['quality_tier'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Quality tier'),
        '#description'   => $this->t('Relative capability, used by smart routing: prefer the cheapest model whose tier satisfies the route.'),
        '#options'       => [
          1 => $this->t('1 — Minimal (tiny/draft models)'),
          2 => $this->t('2 — Basic (small local models)'),
          3 => $this->t('3 — Solid (mid-size, most local chat)'),
          4 => $this->t('4 — Strong (large open / good hosted)'),
          5 => $this->t('5 — Frontier'),
        ],
        '#empty_option'  => $this->t('- Unrated -'),
        '#default_value' => $model->getQualityTier(),
      ];

      $reasoning_help = $model->supportsFeature('reasoning')
        ? $this->t('This model reports a <em>reasoning</em> catalog feature. Effort is sent as <code>reasoning_effort</code> on chat requests when set; leave as default to use the server-side setting.')
        : $this->t('Sent as <code>reasoning_effort</code> on chat requests. Leave as default for non-reasoning models or to keep the server-side setting. (Catalog did not report a reasoning feature for this model.)');
      $element[$key]['reasoning'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Reasoning effort'),
        '#description'   => $reasoning_help,
        '#options'       => [
          'none'   => $this->t('None (disable thinking)'),
          'low'    => $this->t('Low'),
          'medium' => $this->t('Medium'),
          'high'   => $this->t('High'),
        ],
        '#empty_option'  => $this->t('- Server default -'),
        '#default_value' => $model->getReasoning(),
      ];

      $sampling = $model->getSampling();
      $element[$key]['sampling'] = [
        '#type'  => 'details',
        '#title' => $this->t('Sampling overrides'),
        '#open'  => $sampling !== [],
        '#description' => $this->t('Sent on every chat request to this model, overriding the generic provider settings. Leave empty to use the server default. Duplicate a model entity with different values to tune the same model per use case.'),
      ];
      $element[$key]['sampling']['temperature'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Temperature'),
        '#default_value' => $sampling['temperature'] ?? NULL,
        '#min'           => 0,
        '#max'           => 2,
        '#step'          => 'any',
      ];
      $element[$key]['sampling']['top_p'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Top P'),
        '#default_value' => $sampling['top_p'] ?? NULL,
        '#min'           => 0,
        '#max'           => 1,
        '#step'          => 'any',
      ];
      $element[$key]['sampling']['frequency_penalty'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Frequency penalty'),
        '#default_value' => $sampling['frequency_penalty'] ?? NULL,
        '#min'           => -2,
        '#max'           => 2,
        '#step'          => 'any',
      ];
      $element[$key]['sampling']['presence_penalty'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Presence penalty'),
        '#default_value' => $sampling['presence_penalty'] ?? NULL,
        '#min'           => -2,
        '#max'           => 2,
        '#step'          => 'any',
      ];

      $extra = $model->getExtraParams();
      $element[$key]['extra_params_preset'] = [
        '#type'         => 'select',
        '#title'        => $this->t('Add a known parameter set'),
        '#description'  => $this->t('Merges a documented provider-native parameter set into the field below on save, where it can then be edited or removed.'),
        '#options'      => array_map(
          static fn (array $preset) => $preset['label'] ?? '',
          $this->getExtraParamPresets(),
        ),
        '#empty_option' => $this->t('- None -'),
      ];
      $element[$key]['extra_params'] = [
        '#type'          => 'textarea',
        '#title'         => $this->t('Extra request parameters (YAML)'),
        '#description'   => $this->t('Merged verbatim into every chat request to this model, for parameters this module does not model itself. Example, OpenAI built-in web search: <code>tools:<br />&nbsp;&nbsp;- type: web_search</code><br />Servers that do not understand a parameter usually ignore it, but strict ones will return an error. Note that Drupal function-calling tools passed by the calling module override a <code>tools</code> key set here.'),
        '#default_value' => $extra === [] ? '' : trim(Yaml::dump($extra, 4, 2)),
        '#rows'          => 4,
      ];

      $usage = $this->usageTracker->getToday($key);
      $element[$key]['usage_today'] = [
        '#markup' => $this->t('<p>Usage today: @requests requests, @tokens tokens.</p>', [
          '@requests' => $usage['requests'],
          '@tokens' => $usage['input_tokens'] + $usage['output_tokens'],
        ]),
      ];

      $element[$key]['context_length'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Context length (tokens)'),
        '#description'   => $this->t('Auto-detected when the server exposes it (llama.cpp reports the training context). Leave empty if unknown.'),
        '#default_value' => $model->getContextLength(),
        '#min'           => 0,
        '#step'          => 1,
      ];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    // Number elements submit '' when empty, which PHP cannot coerce onto the
    // typed int/?int entity properties. Normalize here (not in validateForm):
    // EntityForm::afterBuild() builds the entity before validation runs.
    foreach (['daily_request_limit', 'daily_token_limit', 'alert_threshold', 'limit_grace'] as $key) {
      $value = $form_state->getValue($key);
      $form_state->setValue($key, ($value === '' || $value === NULL) ? NULL : (int) $value);
    }
    $form_state->setValue('timeout', (int) ($form_state->getValue('timeout') ?: 600));
    parent::copyFormValuesToEntity($entity, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Host/port are hidden (#states) for backends with a fixed endpoint, but
    // hidden fields still submit: drop stale values typed before a backend
    // switch so they never reach the entity.
    if ($this->getBackendDefaultUri((string) $form_state->getValue('backend'))) {
      $form_state->setValue('host_name', '');
      $form_state->setValue('port', '');
    }

    foreach (($form_state->getValue('overrides') ?? []) as $key => $values) {
      $yaml = trim((string) ($values['extra_params'] ?? ''));
      if ($yaml === '') {
        continue;
      }
      try {
        $parsed = Yaml::parse($yaml);
      }
      catch (ParseException $e) {
        $parsed = NULL;
        $error = $e->getMessage();
      }
      if (!is_array($parsed)) {
        $form_state->setErrorByName("overrides][$key][extra_params", $this->t(
          'Extra request parameters for @model must be a YAML mapping: @error',
          ['@model' => $key, '@error' => $error ?? $this->t('not a mapping')],
        ));
      }
    }

    // The key-refresh button only needs a form rebuild, not a connection
    // test (its errors are discarded anyway by #limit_validation_errors).
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'refresh_keys') {
      return;
    }

    // Connection test: ask the selected backend to list models, so the check
    // exercises the same protocol path used later for discovery.
    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server */
    $server = $this->buildEntity($form, $form_state);
    try {
      $backend = $this->backendManager->createInstance($server->getBackend());
      // Stash the catalog so the "Test connection & list models" AJAX button
      // can render it without a second request to the server.
      $form_state->set('aip_detected_models', $backend->listModels($server));
    }
    catch (\Throwable $e) {
      $this->logger('ai_provider_universal')->error(
        'Connection test failed for server @id (backend @backend): @message',
        [
          '@id' => $server->id() ?? '(new)',
          '@backend' => $server->getBackend(),
          '@message' => $e->getMessage(),
        ],
      );
      $form_state->setErrorByName('host_name', $this->t('Could not connect to the server. Check the host, port, backend and API key.'));
    }
  }

  /**
   * AJAX callback: re-renders the key select with freshly created keys.
   */
  public function refreshKeysAjax(array &$form, FormStateInterface $form_state): array {
    return $form['connection']['api_key'];
  }

  /**
   * AJAX callback: shows the models the server reports, without saving.
   */
  public function detectModelsAjax(array &$form, FormStateInterface $form_state): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['id' => 'aip-detect-results'],
      'messages' => ['#type' => 'status_messages'],
    ];
    $models = $form_state->get('aip_detected_models');
    if (!$form_state->getErrors() && is_array($models)) {
      $ids = array_column($models, 'id');
      sort($ids);
      $build['list'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Connection OK — @count models available (model filter not applied):', ['@count' => count($ids)]),
        '#items' => $ids,
      ];
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server */
    $server = $this->entity;

    // Persist the model filter explicitly. Config entity forms do not
    // auto-map arbitrary form values onto the entity, so without this the
    // filter would never be saved and discovery below would run unfiltered.
    $server->set('model_filter', (string) $form_state->getValue('model_filter', ''));

    $status = $server->save();

    $this->logger('ai_provider_universal')->notice(
      'Server @id (@label, backend @backend) @action.',
      [
        '@id' => $server->id(),
        '@label' => $server->label(),
        '@backend' => $server->getBackend(),
        '@action' => $status === SAVED_NEW ? 'created' : 'updated',
      ],
    );

    // Discover models (write path) so the edit form and AI settings can use
    // them. getConfiguredModels() is read-only; persisting model entities is an
    // explicit action triggered here on save.
    //
    // Call the ModelCatalog service directly with the just-saved $server entity
    // (which already carries the new filter). This avoids going through the AI
    // subsystem's ProviderProxy (untyped magic __call forwarding) and also
    // sidesteps any stale static-cache reload, since we pass the live entity
    // instead of reloading by ID.
    try {
      $models = $this->modelCatalog->discoverModels($server);
      $this->messenger()->addStatus($this->formatPlural(
        count($models),
        'Discovered 1 model on server %label.',
        'Discovered @count models on server %label.',
        ['%label' => $server->label()],
      ));
    }
    catch (\Throwable $e) {
      // Connectivity is validated in validateForm(); discovery may still fail
      // if the server is temporarily unreachable after save. Surface it instead
      // of silently swallowing, so the user knows why no models appeared.
      $this->logger('ai_provider_universal')->error(
        'Model discovery failed for server @id after save: @message',
        ['@id' => $server->id(), '@message' => $e->getMessage()],
      );
      $this->messenger()->addWarning($this->t('The server was saved, but model discovery failed: @message. Check the server is reachable and re-run discovery.', [
        '@message' => $e->getMessage(),
      ]));
    }

    // Persist manual operation type overrides on the model config entities.
    if (!$server->isNew()) {
      $this->saveModelOverrides($form_state, $server->id());
    }

    $this->messenger()->addMessage($this->t('Server %label has been @action.', [
      '%label' => $server->label(),
      '@action' => $status === SAVED_NEW ? $this->t('created') : $this->t('updated'),
    ]));

    $form_state->setRedirectUrl($server->toUrl('collection'));

    return $status;
  }

  /**
   * Loads the known extra-parameter presets from definitions/extra_params.yml.
   *
   * @return array<string, array{label: string, params: array}>
   *   Presets keyed by machine name.
   */
  protected function getExtraParamPresets(): array {
    static $presets;
    if ($presets === NULL) {
      // Same relative resolution as ModelDefaults: no container access needed.
      $presets = Yaml::parseFile(dirname(__DIR__, 2) . '/definitions/extra_params.yml') ?: [];
    }
    return $presets;
  }

  /**
   * Persists manual model capability overrides to ai_universal_model entities.
   */
  protected function saveModelOverrides(FormStateInterface $form_state, string $server_id): void {
    $model_storage = $this->entityTypeManager->getStorage('ai_universal_model');
    $models = $model_storage->loadByProperties(['server_id' => $server_id]);

    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model */
    foreach ($models as $model) {
      $key = $model->id();
      $values = $form_state->getValue(['overrides', $key], []);
      if (!is_array($values) || $values === []) {
        continue;
      }

      $selected = array_values(array_filter($values['operation_types'] ?? []));
      $model->setOperationTypes($selected);

      $toNumber = static fn ($v) => ($v === '' || $v === NULL) ? NULL : (float) $v;
      $model->setCostInput($toNumber($values['cost_input'] ?? NULL));
      $model->setCostOutput($toNumber($values['cost_output'] ?? NULL));

      $tier = $values['quality_tier'] ?? '';
      $model->setQualityTier($tier === '' || $tier === NULL ? NULL : (int) $tier);

      $ctx = $values['context_length'] ?? '';
      $model->setContextLength($ctx === '' || $ctx === NULL ? NULL : (int) $ctx);

      $reasoning = $values['reasoning'] ?? '';
      $model->setReasoning($reasoning === '' ? NULL : (string) $reasoning);

      // setSampling() drops empty/non-numeric values, so cleared fields
      // fall back to the server default.
      $model->setSampling(array_filter(
        $values['sampling'] ?? [],
        static fn ($v) => $v !== '' && $v !== NULL,
      ));

      // Already validated as a YAML mapping in validateForm().
      $yaml = trim((string) ($values['extra_params'] ?? ''));
      $params = $yaml === '' ? [] : (array) Yaml::parse($yaml);
      $preset = (string) ($values['extra_params_preset'] ?? '');
      if ($preset !== '') {
        // Preset wins on conflict: picking it is an explicit request for it.
        $params = ($this->getExtraParamPresets()[$preset]['params'] ?? []) + $params;
      }
      $model->setExtraParams($params);

      $model->save();
    }
  }

}
