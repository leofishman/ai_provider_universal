<?php

namespace Drupal\ai_provider_universal\Plugin\AiProvider;

use OpenAI\Client;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\Moderation\ModerationInput;
use Drupal\ai\OperationType\Moderation\ModerationInterface;
use Drupal\ai\OperationType\Moderation\ModerationOutput;
use Drupal\ai\OperationType\Moderation\ModerationResponse;
use Drupal\ai\OperationType\Rerank\ReRankInput;
use Drupal\ai\OperationType\Rerank\ReRankInterface;
use Drupal\ai\OperationType\Rerank\ReRankOutput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageInterface;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ai_provider_universal\Entity\AiUniversalModelInterface;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\ai_provider_universal\Event\ModelPreCallEvent;
use Drupal\ai_provider_universal\Models\Moderation\LlamaGuard3;
use Drupal\ai_provider_universal\Models\Moderation\ShieldGemma;
use Drupal\ai_provider_universal\Service\ModelCatalog;
use Drupal\ai_provider_universal\Service\UsageTracker;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Universal multi-instance AI provider plugin.
 *
 * This is a single non-derived plugin. Multi-server support is achieved via
 * ai_universal_server config entities + ai_universal_model config entities.
 * Model IDs are unique across servers to allow the AI module to pick specific
 * backends/models without using plugin derivatives.
 */
#[AiProvider(
  id: 'universal',
  label: new TranslatableMarkup('Universal'),
)]
class UniversalProvider extends OpenAiBasedProviderClientBase implements ReRankInterface, ModerationInterface, TextToImageInterface {

  use StringTranslationTrait;
  use ChatTrait;

  /**
   * All operation types this provider can support.
   */
  const SUPPORTED_OPERATION_TYPES = ['chat', 'embeddings', 'speech_to_text', 'rerank', 'moderation', 'text_to_image'];

  /**
   * Map from moderation model name patterns to parser classes.
   */
  const MODERATION_PARSERS = [
    'llama-guard3' => LlamaGuard3::class,
    'llamaguard'   => LlamaGuard3::class,
    'llama_guard'  => LlamaGuard3::class,
    'shieldgemma'  => ShieldGemma::class,
    'shield_gemma' => ShieldGemma::class,
    'shield-gemma' => ShieldGemma::class,
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The HTTP client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected ClientFactory $httpClientFactory;

  /**
   * Model catalog service (handles discovery and listing).
   */
  protected ModelCatalog $modelCatalog;

  /**
   * Cached model mapping (machine_id => raw_id).
   *
   * @var array
   */
  protected array $models = [];

  /**
   * Cached server entity.
   *
   * @var \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface|null|false
   */
  protected AiUniversalServerInterface|null|false $serverEntity = FALSE;

  /**
   * The service container.
   *
   * Used to look up optional submodule services (router, factcheck) that
   * cannot be constructor-injected because they may not be installed.
   */
  protected ContainerInterface $serviceContainer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->configuration = $configuration;
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->httpClientFactory = $container->get('http_client_factory');
    $instance->modelCatalog = $container->get(ModelCatalog::class);
    $instance->serviceContainer = $container;
    return $instance;
  }

  /**
   * Gets the server config entity.
   *
   * Resolution order:
   * 1. Explicit server_id passed via plugin $configuration when instantiated.
   * 2. Active server set for the current model op (setActiveServerForModel).
   * 3. NULL (generic/validation paths that inject host_name into config).
   *
   * @return \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface|null
   *   The resolved server entity, or NULL when there is no server context.
   */
  protected function getServerEntity(): ?AiUniversalServerInterface {
    if ($this->serverEntity === FALSE) {
      $server_id = $this->configuration['server_id'] ?? NULL;

      if (!$server_id && $this->activeServerId) {
        $server_id = $this->activeServerId;
      }

      if ($server_id) {
        $entity = $this->entityTypeManager
          ->getStorage('ai_universal_server')
          ->load($server_id);
        $this->serverEntity = $entity instanceof AiUniversalServerInterface ? $entity : NULL;
      }
      else {
        $this->serverEntity = NULL;
      }
    }
    return $this->serverEntity;
  }

  /**
   * Active server ID for the duration of a model-specific operation.
   *
   * @var string|null
   */
  protected ?string $activeServerId = NULL;

  /**
   * Resolve and set the active server based on a model identifier.
   *
   * The model identifier here is the key returned by getConfiguredModels()
   * (which is the ai_universal_model entity id).
   */
  protected function setActiveServerForModel(string $model_key): void {
    $this->activeServerId = NULL;
    $this->serverEntity = FALSE;

    // Try to load the model entity to find its server.
    $model = $this->entityTypeManager
      ->getStorage('ai_universal_model')
      ->load($model_key);

    if ($model instanceof AiUniversalModelInterface) {
      $this->activeServerId = $model->getServerId();
    }
    elseif (str_contains($model_key, '__')) {
      // Compatibility fallback for old-style compound keys ("server__machine").
      // Post-2.0 all model keys are ai_universal_model entity IDs.
      [$maybe_server] = explode('__', $model_key, 2);
      if ($this->entityTypeManager->getStorage('ai_universal_server')->load($maybe_server)) {
        $this->activeServerId = $maybe_server;
      }
    }
  }

  /**
   * Clear any active server context (call after an operation if needed).
   */
  protected function clearActiveServer(): void {
    $this->activeServerId = NULL;
    $this->serverEntity = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    $server = $this->getServerEntity();

    if ($server) {
      // Specific server context. The backend decides whether the server is
      // reachable in principle (some backends have a default endpoint and
      // need no host).
      if ($this->modelCatalog->getBackend($server)->getBaseUri($server) === '') {
        return FALSE;
      }
      if ($operation_type) {
        return in_array($operation_type, $this->getSupportedOperationTypes());
      }
      return TRUE;
    }

    // Generic case (no specific server/model selected, e.g. the requirements
    // check or form options). The provider is "set up" if at least one server
    // has a host. When a specific operation type is requested we additionally
    // require a discovered model that supports it, so capability reporting
    // stays
    // honest — but the bare "is a provider configured?" check (no operation
    // type) must not depend on discovery having run yet.
    $server_storage = $this->entityTypeManager->getStorage('ai_universal_server');
    $servers = $server_storage->loadMultiple();

    foreach ($servers as $srv) {
      if ($this->modelCatalog->getBackend($srv)->getBaseUri($srv) === '') {
        continue;
      }
      if ($operation_type === NULL) {
        return TRUE;
      }
      if (!empty($this->modelCatalog->getModelsForServer($srv->id(), $operation_type))) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasAuthentication(): bool {
    $server = $this->getServerEntity();
    return $server && !empty($server->getApiKey());
  }

  /**
   * {@inheritdoc}
   */
  protected function createClient(): Client {
    // If the server doesn't use authentication, we must still supply a dummy
    // API
    // key to the OpenAI client factory because its transporter requires one.
    if (!$this->hasAuthentication()) {
      $clientFactory = \OpenAI::factory();
      $client = $clientFactory->withHttpClient($this->httpClient);
      $client = $client->withApiKey('no-key');
      if ($this->getEndpoint()) {
        $client = $client->withBaseUri($this->getEndpoint());
      }
      return $client->make();
    }
    return parent::createClient();
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    parent::setAuthentication($authentication);
    $this->client = NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadApiKey(): string {
    $server = $this->getServerEntity();
    $key_id = $server?->getApiKey() ?? '';
    if ($key_id === '') {
      throw new AiSetupFailureException(
        sprintf(
          'Could not load the %s API key, please check your environment settings or your setup key.',
          $this->getPluginDefinition()['label']
        ),
      );
    }

    $api_key = $this->keyRepository->getKey($key_id)?->getKeyValue();
    if (empty($api_key)) {
      throw new AiSetupFailureException(
        sprintf(
          'Could not load the %s API key, please check your environment settings or your setup key.',
          $this->getPluginDefinition()['label']
        ),
      );
    }

    return $api_key;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    $server = $this->getServerEntity();
    if ($server) {
      $types = $server->getOperationTypes();
      if (!empty($types)) {
        return $types;
      }
    }

    // No specific server context: union across all servers.
    $storage = $this->entityTypeManager->getStorage('ai_universal_server');
    $servers = $storage->loadMultiple();
    $union = [];
    foreach ($servers as $srv) {
      $t = $srv->getOperationTypes();
      if (empty($t)) {
        // One unrestricted server => all.
        return self::SUPPORTED_OPERATION_TYPES;
      }
      $union = array_unique(array_merge($union, $t));
    }
    return $union ?: self::SUPPORTED_OPERATION_TYPES;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadClient(): void {
    if (empty($this->client)) {
      $server = $this->getServerEntity();

      // With a server context the backend plugin owns the base URI (it may
      // have a protocol-specific default, e.g. Fireworks). The legacy
      // host-in-configuration path remains for validation flows without a
      // server entity.
      if ($server) {
        $this->setEndpoint($this->modelCatalog->getBackend($server)->getBaseUri($server));
      }
      else {
        $host = $this->getBaseHost();
        if (!$host) {
          throw new AiRequestErrorException('Server host is not configured.');
        }
        $this->setEndpoint(rtrim($host, '/') . '/v1');
      }

      $timeout = 600;
      if ($server) {
        $timeout = $server->getTimeout() ?: 600;
      }
      $timeout = $this->configuration['timeout'] ?? $timeout;

      $clientOptions = ['timeout' => $timeout];
      if ($server) {
        // Backend-specific default headers (e.g. OpenRouter attribution).
        // Guzzle applies them only when a request doesn't set them itself.
        $headers = $this->modelCatalog->getBackend($server)->getHttpHeaders($server);
        if ($headers) {
          $clientOptions['headers'] = $headers;
        }
      }
      $this->setHttpClient($this->httpClientFactory->fromOptions($clientOptions));
      $this->client = $this->createClient();
    }
  }

  /**
   * {@inheritdoc}
   *
   * Read-only: this never writes configuration. Model discovery (creating
   * or updating ai_universal_model config entities) happens explicitly when
   * a server is saved (see AiUniversalServerForm) or via ::discoverModels().
   * Keeping this read path side-effect free avoids polluting config sync
   * with runtime data pulled from a remote server, and prevents config
   * writes on cache-cold reads from the AI subsystem.
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    $server = $this->getServerEntity();

    // Specific server context (a concrete server_id was injected): a flat list
    // is unambiguous.
    if ($server) {
      return $this->modelCatalog->getModelsForServer($server->id(), $operation_type);
    }

    // Generic context (e.g. the AI settings default-providers form aggregates
    // every server). Group by server so the model select renders <optgroup>s
    // per server, keeping options short and unambiguous even when two servers
    // expose a model with the same raw id.
    $grouped = $this->modelCatalog->getModelsGroupedByServer($operation_type);

    // When the router submodule is enabled, expose each smart route as a
    // virtual model ("route__<id>"); it resolves to a real model per request.
    $routes = $this->getRouteModelOptions($operation_type);
    if ($routes) {
      $grouped = [(string) $this->t('Smart Routing') => $routes] + $grouped;
    }

    return $grouped;
  }

  /**
   * Lists smart routes as virtual model options, if the router is enabled.
   *
   * @return array<string, string>
   *   Map of "route__<id>" => route label.
   */
  protected function getRouteModelOptions(?string $operation_type): array {
    if (!$this->entityTypeManager->hasDefinition('ai_universal_route')) {
      return [];
    }
    $options = [];
    foreach ($this->entityTypeManager->getStorage('ai_universal_route')->loadMultiple() as $route) {
      if ($operation_type === NULL || $route->getOperationType() === $operation_type) {
        $options['route__' . $route->id()] = (string) $this->t('Auto: @label', ['@label' => $route->label()]);
      }
    }
    return $options;
  }

  /**
   * Resolves a virtual "route__<id>" model to a real model entity id.
   *
   * No-op for regular model ids. Requires the router submodule when a route
   * id is used (the option only appears in the UI when it is enabled, so a
   * missing service here means it was uninstalled after configuration).
   */
  protected function resolveRoutedModel(string $model_id, mixed $input, string $operation_type): string {
    if (!str_starts_with($model_id, 'route__')) {
      return $model_id;
    }
    if (!$this->serviceContainer->has('ai_provider_universal_router.decider')) {
      throw new AiSetupFailureException(sprintf('Model "%s" is a smart route, but the ai_provider_universal_router module is not enabled.', $model_id));
    }
    return $this->serviceContainer->get('ai_provider_universal_router.decider')
      ->resolve(substr($model_id, 7), $input, $operation_type);
  }

  /**
   * Discovers models from the active server and persists them as entities.
   *
   * This is the write path: call it only from explicit user actions (saving a
   * server) or maintenance commands, never from a read/render path.
   *
   * @return array<string, string>
   *   Map of model entity id => raw model id for the active server. Falls back
   *   to the already-known models if the server is unreachable.
   */
  public function discoverModels(): array {
    $server = $this->getServerEntity();
    if (!$server) {
      return [];
    }

    try {
      return $this->modelCatalog->discoverModels($server);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_provider_universal')->error(
        'Failed to get models from server: @message',
        ['@message' => $e->getMessage()]
      );
      return $this->modelCatalog->getModelsForServer($server->id());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $route_id = NULL;
    if (str_starts_with($model_id, 'route__')) {
      $route_id = substr($model_id, 7);
      $model_id = $this->resolveRoutedModel($model_id, $input, 'chat');
    }

    $output = $this->doChat($input, $model_id, $tags);

    if ($route_id !== NULL) {
      $output = $this->maybeEscalate($route_id, $input, $model_id, $output, $tags);
    }
    return $output;
  }

  /**
   * Pre-call gate: lets other modules block the call or swap the model.
   *
   * Dispatched after route resolution, so subscribers always see a concrete
   * ai_universal_model entity id.
   *
   * @return string
   *   The (possibly swapped) model entity id to use.
   *
   * @throws \Drupal\ai\Exception\AiRequestErrorException
   *   When a subscriber blocks the call.
   */
  protected function preCallGate(string $model_id, string $operation_type): string {
    $event = new ModelPreCallEvent($model_id, $operation_type);
    $this->serviceContainer->get('event_dispatcher')->dispatch($event, ModelPreCallEvent::EVENT_NAME);
    if ($event->isBlocked()) {
      $this->loggerFactory->get('ai_provider_universal')->warning(
        'A @type call to @model was blocked by a pre-call subscriber: @reason',
        ['@type' => $operation_type, '@model' => $model_id, '@reason' => $event->getBlockReason()],
      );
      throw new AiRequestErrorException(sprintf('Call to model "%s" blocked: %s', $model_id, $event->getBlockReason()));
    }
    return $event->getModelId();
  }

  /**
   * Executes one chat call against a concrete model entity id.
   */
  protected function doChat(array|string|ChatInput $input, string $model_id, array $tags): ChatOutput {
    $model_id = $this->preCallGate($model_id, 'chat');
    $this->setActiveServerForModel($model_id);

    // Per-model reasoning override. The parent builds the request payload
    // from $this->configuration, so merging here reaches every chat call.
    // "reasoning_effort" is the OpenAI-compatible parameter (OpenAI, vLLM,
    // llama.cpp, Fireworks); lenient local servers ignore it when unsupported.
    $model = $this->entityTypeManager->getStorage('ai_universal_model')->load($model_id);
    $had_reasoning = array_key_exists('reasoning_effort', $this->configuration);
    $previous_reasoning = $this->configuration['reasoning_effort'] ?? NULL;
    if ($model instanceof AiUniversalModelInterface && ($effort = $model->getReasoning()) !== NULL) {
      $this->configuration['reasoning_effort'] = $effort;
    }

    // Usage limits are enforced per server by the router submodule; without
    // it counters are still recorded but nothing blocks.
    if ($model instanceof AiUniversalModelInterface
      && $this->serviceContainer->has('ai_provider_universal_router.limits')) {
      $server = $this->entityTypeManager->getStorage('ai_universal_server')->load($model->getServerId());
      if ($server instanceof AiUniversalServerInterface
        && $this->serviceContainer->get('ai_provider_universal_router.limits')->isServerOverLimit($server)) {
        $this->loggerFactory->get('ai_provider_universal')->warning(
          'Server @server rejected a chat request to @model: daily usage limit reached.',
          ['@server' => $server->id(), '@model' => $model_id],
        );
        $this->clearActiveServer();
        throw new AiQuotaException(sprintf('Server "%s" has reached its daily usage limit.', $server->id()));
      }
    }

    try {
      $resolved = $this->getModel($model_id);
      $output = parent::chat($input, $resolved, $tags);
      $usage = $output->getTokenUsage();
      $this->serviceContainer->get(UsageTracker::class)->record($model_id, $usage->input, $usage->output);
      return $output;
    }
    finally {
      if ($had_reasoning) {
        $this->configuration['reasoning_effort'] = $previous_reasoning;
      }
      else {
        unset($this->configuration['reasoning_effort']);
      }
      $this->clearActiveServer();
    }
  }

  /**
   * Fact-check cascade: verify a routed answer, retry stronger on failure.
   *
   * Only active when the route enables fact checking and the factcheck
   * module is installed with a checker model configured. The escalated
   * answer is returned as-is (verified best effort, not re-verified, to
   * bound cost at one escalation per request).
   */
  protected function maybeEscalate(string $route_id, array|string|ChatInput $input, string $model_id, ChatOutput $output, array $tags): ChatOutput {
    $container = $this->serviceContainer;
    if (!$container->has('ai_provider_universal_factcheck.checker')) {
      return $output;
    }

    $route = $this->entityTypeManager->getStorage('ai_universal_route')->load($route_id);
    if (!$route || !$route->isFactcheckEnabled()) {
      return $output;
    }

    $checker = $container->get('ai_provider_universal_factcheck.checker');
    if (!$checker->isConfigured()) {
      return $output;
    }

    $question = $input instanceof ChatInput
      ? implode("\n", array_map(static fn ($m) => $m->getText(), $input->getMessages()))
      : (is_string($input) ? $input : '');

    $result = $checker->verify($question, $output->getNormalized()->getText());
    if ($result['score'] >= $route->getFactcheckMinScore()) {
      return $output;
    }

    $best = $container->get('ai_provider_universal_router.decider')
      ->resolveBest($route_id, 'chat', [$model_id]);
    if (!$best) {
      return $output;
    }

    $this->loggerFactory->get('ai_provider_universal')->info(
      'Fact check failed for route @route (score @score < @min, model @model); escalating to @best.',
      [
        '@route' => $route_id,
        '@score' => round($result['score'], 2),
        '@min' => $route->getFactcheckMinScore(),
        '@model' => $model_id,
        '@best' => $best,
      ],
    );

    return $this->doChat($input, $best, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $model_id = $this->resolveRoutedModel($model_id, $input instanceof EmbeddingsInput ? '' : $input, 'embeddings');
    $model_id = $this->preCallGate($model_id, 'embeddings');
    $this->setActiveServerForModel($model_id);
    try {
      $resolved = $this->getModel($model_id);
      return parent::embeddings($input, $resolved, $tags);
    }
    finally {
      $this->clearActiveServer();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string|SpeechToTextInput $input, string $model_id, array $tags = []): SpeechToTextOutput {
    $model_id = $this->preCallGate($model_id, 'speech_to_text');
    $this->setActiveServerForModel($model_id);
    try {
      $resolved = $this->getModel($model_id);
      return parent::speechToText($input, $resolved, $tags);
    }
    finally {
      $this->clearActiveServer();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rerank(ReRankInput $input, string $model_id, array $tags = []): ReRankOutput {
    $model_id = $this->resolveRoutedModel($model_id, $input->getQuery(), 'rerank');
    $model_id = $this->preCallGate($model_id, 'rerank');
    $this->setActiveServerForModel($model_id);
    $this->loadClient();
    $raw_model_id = $this->getModel($model_id);

    $payload = [
      'model'     => $raw_model_id,
      'query'     => $input->getQuery(),
      'documents' => $input->getInputs(),
    ];
    if ($input->getTopN() > 0) {
      $payload['top_n'] = $input->getTopN();
    }

    try {
      $timeout = 600;
      $server = $this->getServerEntity();
      if ($server) {
        $timeout = $server->getTimeout() ?: 600;
      }

      $options = ['json' => $payload];
      if ($this->hasAuthentication()) {
        $options['headers'] = ['Authorization' => 'Bearer ' . $this->loadApiKey()];
      }

      $response = $this->httpRequest(
        'POST',
        rtrim($this->getBaseHost(), '/') . '/v1/rerank',
        $options,
        $timeout,
      );
      $data = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Throwable $e) {
      $this->handleApiThrowable($e);
      throw $e;
    }

    $output = new ReRankOutput(
      $data['results'] ?? [],
      $data['id'] ?? '',
      $data,
    );
    $this->clearActiveServer();
    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string|ModerationInput $input, ?string $model_id = NULL, array $tags = []): ModerationOutput {
    $prompt = $input instanceof ModerationInput ? $input->getPrompt() : $input;
    if ($model_id) {
      $model_id = $this->resolveRoutedModel($model_id, $prompt, 'moderation');
      $model_id = $this->preCallGate($model_id, 'moderation');
      $this->setActiveServerForModel($model_id);
    }
    $this->loadClient();

    $raw_model_id = $this->getModel($model_id ?? '');

    $parser_class = $this->getModerationParser($raw_model_id);

    $is_shield = $parser_class === ShieldGemma::class
      || preg_match('/shield.?gemma/', strtolower($raw_model_id));

    // ShieldGemma's chat template requires a `guideline` variable, so a plain
    // chat.completions call fails with "'guideline' is undefined". Instead we
    // post a fully-built prompt to /v1/completions once per safety policy and
    // flag the content if any policy is violated.
    if ($is_shield) {
      // Resolve the request timeout the same way as the other custom paths.
      $timeout = 600;
      $server = $this->getServerEntity();
      if ($server) {
        $timeout = $server->getTimeout() ?: 600;
      }
      $timeout = $this->configuration['timeout'] ?? $timeout;

      $url = rtrim($this->getBaseHost(), '/') . '/v1/completions';
      $headers = [];
      if ($this->hasAuthentication()) {
        $headers['Authorization'] = 'Bearer ' . $this->loadApiKey();
      }

      $flagged = FALSE;
      $categories = [];
      $raw_outputs = [];
      foreach (ShieldGemma::getDefaultGuidelines() as $category => $guideline) {
        $payload = [
          'model'       => $raw_model_id,
          'prompt'      => ShieldGemma::buildPrompt($prompt, $guideline),
          'max_tokens'  => 4,
          'temperature' => 0.0,
        ];

        try {
          $options = ['json' => $payload];
          if ($headers) {
            $options['headers'] = $headers;
          }
          $http_response = $this->httpRequest('POST', $url, $options, $timeout);
          $response = json_decode($http_response->getBody()->getContents(), TRUE);
        }
        catch (\Throwable $e) {
          $this->handleApiThrowable($e);
          throw $e;
        }

        if (!is_array($response)) {
          throw new AiRequestErrorException('Invalid JSON response from completions endpoint.');
        }
        if (isset($response['error'])) {
          throw new AiRequestErrorException('Completions error from ShieldGemma: ' . json_encode($response['error']));
        }

        $text = trim($response['choices'][0]['text'] ?? '');
        $violated = ShieldGemma::responseIndicatesViolation($text);
        $categories[$category] = $violated;
        $raw_outputs[$category] = $text;
        $flagged = $flagged || $violated;
      }

      $moderation_response = new ModerationResponse($flagged, ['categories' => $categories]);
      $out = new ModerationOutput($moderation_response, $raw_outputs, ['categories' => $categories]);
      $this->clearActiveServer();
      return $out;
    }

    // Default path for LlamaGuard3 and unknown parsers: use chat completions.
    // (Their templates are typically satisfied by a plain user message or the
    // server is configured with an appropriate --chat-template.)
    $payload = [
      'model' => $raw_model_id,
      'messages' => [
        ['role' => 'user', 'content' => $prompt],
      ],
    ] + $this->configuration;

    try {
      $response = $this->client->chat()->create($payload)->toArray();
    }
    catch (\Throwable $e) {
      $this->handleApiThrowable($e);
      throw $e;
    }

    if (!isset($response['choices'][0]['message']['content'])) {
      throw new AiRequestErrorException('No content in moderation response.');
    }
    $message = $response['choices'][0]['message']['content'];

    if ($parser_class) {
      $moderation_response = $parser_class::parse($message);
    }
    else {
      $flagged = str_contains(strtolower($message), 'unsafe');
      $moderation_response = new ModerationResponse($flagged);
    }

    $out = new ModerationOutput($moderation_response, $message, $response);
    $this->clearActiveServer();
    return $out;
  }

  /**
   * Finds the moderation parser class for a model ID.
   *
   * @param string $model_id
   *   The raw model ID.
   *
   * @return string|null
   *   The parser class FQCN, or NULL if unknown.
   */
  protected function getModerationParser(string $model_id): ?string {
    $name = strtolower($model_id);
    foreach (self::MODERATION_PARSERS as $pattern => $class) {
      if (str_contains($name, $pattern)) {
        return $class;
      }
    }
    // Fallback for ShieldGemma id variants (shield-gemma, shield_gemma).
    if (preg_match('/shield.?gemma/', $name)) {
      return ShieldGemma::class;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    $this->setActiveServerForModel($model_id);
    $this->loadClient();
    $raw_model_id = $this->getModel($model_id);
    try {
      $model_response = $this->client->models()->retrieve($raw_model_id);
      /** @var array<string, mixed> $data */
      $data = (array) $model_response->toArray();
      if (!empty($data['embedding']) && is_array($data['embedding'])) {
        return (int) ($data['embedding']['size'] ?? count($data['embedding']));
      }
      if (!empty($data['hidden_size'])) {
        return (int) $data['hidden_size'];
      }
      if (!empty($data['context_length'])) {
        return (int) $data['context_length'];
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ai_provider_universal')->warning(
        'Could not determine embedding vector size for model @model: @message',
        ['@model' => $raw_model_id, '@message' => $e->getMessage()]
      );
    }
    $this->clearActiveServer();
    return parent::embeddingsVectorSize($model_id);
  }

  /**
   * Tests connectivity to the server.
   *
   * @throws \Drupal\ai\Exception\AiRequestErrorException
   *   If the server is unreachable or not configured.
   */
  public function testConnection(): void {
    $this->loadClient();
    $this->client->models()->list();
  }

  /**
   * Gets the raw model identifier from the stored mapping (or model entities).
   *
   * The $model_id here is the key the AI system uses (ai_universal_model
   * id).
   */
  protected function getModel(string $model_id): string {
    // Resolve the raw model id straight from the model entity. This is the
    // authoritative source and keeps API resolution independent of the display
    // labels returned by getConfiguredModels() (which include the server name).
    $model = $this->entityTypeManager
      ->getStorage('ai_universal_model')
      ->load($model_id);
    if ($model instanceof AiUniversalModelInterface) {
      return $model->getRawModelId();
    }
    return $model_id;
  }

  /**
   * Gets the base host URL for the server.
   *
   * Priority:
   * 1. Config entity (normal 2.0 multi-server path).
   * 2. Explicit $configuration passed at instantiation.
   * 3. Legacy simple config (ai_provider_universal.settings) — shim for
   *    validation paths and direct plugin use; empty on fresh 2.0 sites.
   */
  protected function getBaseHost(): string {
    $server = $this->getServerEntity();
    if ($server) {
      $host = rtrim((string) $server->getHostName(), '/');
      $port = $server->getPort();
    }
    else {
      $host = rtrim((string) ($this->configuration['host_name'] ?? $this->getConfig()->get('host_name') ?? ''), '/');
      $port = $this->configuration['port'] ?? $this->getConfig()->get('port') ?? '';
    }
    if ($port) {
      $host .= ':' . $port;
    }
    return $host;
  }

  /**
   * {@inheritdoc}
   */
  public function textToImage(string|TextToImageInput $input, string $model_id, array $tags = []): TextToImageOutput {
    $model_id = $this->preCallGate($model_id, 'text_to_image');
    $this->setActiveServerForModel($model_id);
    try {
      $resolved = $this->getModel($model_id);
      return parent::textToImage($input, $resolved, $tags);
    }
    finally {
      $this->clearActiveServer();
    }
  }

  /**
   * Performs an HTTP request using Drupal's HTTP client factory.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $uri
   *   The request URI.
   * @param array $options
   *   Guzzle request options.
   * @param int $timeout
   *   Request timeout in seconds.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response.
   */
  protected function httpRequest(string $method, string $uri, array $options = [], int $timeout = 60): ResponseInterface {
    $client = $this->httpClientFactory->fromOptions(['timeout' => $timeout]);
    return $client->request($method, $uri, $options);
  }

}
