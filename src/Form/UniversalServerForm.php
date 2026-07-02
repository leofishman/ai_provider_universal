<?php

namespace Drupal\ai_provider_universal\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_provider_universal\Backend\ServerBackendManager;
use Drupal\ai_provider_universal\Service\ModelCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding and editing universal_server entities.
 */
class UniversalServerForm extends EntityForm {

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
    protected ServerBackendManager $backendManager,
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
      $container->get(ServerBackendManager::class),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ai_provider_universal\Entity\UniversalServerInterface $server */
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
        'exists' => '\Drupal\ai_provider_universal\Entity\UniversalServer::load',
      ],
      '#disabled' => !$server->isNew(),
    ];

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection'),
      '#open' => TRUE,
    ];

    $backendOptions = $this->backendManager->getOptions();
    $form['connection']['backend'] = [
      '#type' => 'select',
      '#title' => $this->t('Backend'),
      '#description' => $this->t('The protocol this server speaks. OpenAI-compatible covers llama.cpp, Ollama, vLLM, LM Studio, LiteLLM, Fireworks, OpenAI and similar. Other modules can add native backends.'),
      '#options' => $backendOptions,
      '#default_value' => $server->getBackend(),
      '#required' => TRUE,
      '#access' => count($backendOptions) > 1,
    ];

    $form['connection']['host_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host Name'),
      '#description' => $this->t('The host name including protocol. For DDEV use http://host.docker.internal.'),
      '#required' => TRUE,
      '#default_value' => $server->getHostName(),
      '#attributes' => ['placeholder' => 'http://127.0.0.1'],
    ];

    $form['connection']['port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Port'),
      '#description' => $this->t('Port number. Leave empty for default. llama.cpp default is 8080, Ollama is 11434.'),
      '#default_value' => $server->getPort() ?: '8080',
      '#attributes' => ['placeholder' => '8080'],
    ];

    $form['connection']['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Optional. Select a Key for authenticated servers (e.g. vLLM, LiteLLM). Leave empty for local llama.cpp servers without authentication.'),
      '#default_value' => $server->getApiKey(),
    ];

    $form['connection']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout (seconds)'),
      '#description' => $this->t('Request timeout in seconds. Increase for slow models or large inputs.'),
      '#default_value' => $server->getTimeout() ?: 600,
      '#min' => 5,
      '#max' => 3600,
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
   * Builds the model capability overrides fieldset.
   *
   * Now reads from universal_model config entities (instead of State).
   */
  protected function buildOverridesForm($server): array {
    $server_id = $server->id();

    $element = [
      '#type'  => 'details',
      '#title' => $this->t('Model capability overrides'),
      '#description' => $this->t(
        'Capabilities are auto-detected from server metadata and HuggingFace. Use these overrides for models whose type cannot be auto-detected. Leave all checkboxes unchecked to use auto-detection.'
      ),
      '#open' => FALSE,
      // #tree must be TRUE so each model's checkbox values nest under the
      // 'overrides' parent, matching how saveModelOverrides() reads them
      // (getValue(['overrides', $model_id])). Without it FAPI defaults to
      // FALSE, the values land at the top level, and overrides never persist.
      '#tree' => TRUE,
    ];

    $model_storage = $this->entityTypeManager->getStorage('universal_model');
    $models = $model_storage->loadByProperties(['server_id' => $server_id]);

    if (empty($models)) {
      $element['empty'] = [
        '#markup' => $this->t('<p>No models found yet. Save the server first, then models will be discovered automatically.</p>'),
      ];
      return $element;
    }

    $type_options = array_map([$this, 't'], self::OPERATION_TYPE_LABELS);

    /** @var \Drupal\ai_provider_universal\Entity\UniversalModelInterface $model */
    foreach ($models as $model) {
      $raw_id = $model->getRawModelId();
      $auto_types = $model->getDetectedOperationTypes() ?: ['chat'];
      $auto_label = implode(', ', $auto_types);
      $current_overrides = $model->getOperationTypes();

      // Use model entity id as form key (stable).
      $key = $model->id();

      $element[$key] = [
        '#type'          => 'checkboxes',
        '#title'         => $raw_id,
        '#description'   => $this->t('Auto-detected: <em>@types</em>', ['@types' => $auto_label]),
        '#options'       => $type_options,
        '#default_value' => $current_overrides,
      ];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Connection test: ask the selected backend to list models, so the check
    // exercises the same protocol path used later for discovery.
    /** @var \Drupal\ai_provider_universal\Entity\UniversalServerInterface $server */
    $server = $this->buildEntity($form, $form_state);
    try {
      $backend = $this->backendManager->createInstance($server->getBackend());
      $backend->listModels($server);
    }
    catch (\Throwable) {
      $form_state->setErrorByName('host_name', $this->t('Could not connect to the server. Check the host, port, backend and API key.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\ai_provider_universal\Entity\UniversalServerInterface $server */
    $server = $this->entity;

    // Persist the model filter explicitly. Config entity forms do not
    // auto-map arbitrary form values onto the entity, so without this the
    // filter would never be saved and discovery below would run unfiltered.
    $server->set('model_filter', (string) $form_state->getValue('model_filter', ''));

    $status = $server->save();

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
   * Persists manual model capability overrides to universal_model entities.
   */
  protected function saveModelOverrides(FormStateInterface $form_state, string $server_id): void {
    $model_storage = $this->entityTypeManager->getStorage('universal_model');
    $models = $model_storage->loadByProperties(['server_id' => $server_id]);

    /** @var \Drupal\ai_provider_universal\Entity\UniversalModelInterface $model */
    foreach ($models as $model) {
      $key = $model->id();
      $raw_values = $form_state->getValue(['overrides', $key], []);
      $selected = array_values(array_filter($raw_values));
      $model->setOperationTypes($selected);
      $model->save();
    }
  }

}
