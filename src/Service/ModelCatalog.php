<?php

namespace Drupal\ai_provider_universal\Service;

use Drupal\ai_provider_universal\Backend\AiServerBackendInterface;
use Drupal\ai_provider_universal\Backend\AiServerBackendManager;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\ai_provider_universal\Event\ModelsDiscoveredEvent;
use Drupal\ai_provider_universal\Utility\ModelDefaults;
use Drupal\ai_provider_universal\Utility\ModelFilter;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Handles model discovery, persistence and catalog queries.
 *
 * All protocol-specific work (listing models, detecting capabilities) is
 * delegated to the server's backend plugin; this service only owns the
 * catalog lifecycle: naming, persistence and queries over ai_universal_model
 * config entities.
 */
class ModelCatalog {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TransliterationInterface $transliteration,
    protected AiServerBackendManager $backendManager,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Discovers server models and persists them as ai_universal_model items.
   *
   * @param \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server
   *   The server whose catalog is being (re-)discovered.
   *
   * @return array<string, string>
   *   Map of model entity id => raw model id.
   */
  public function discoverModels(AiUniversalServerInterface $server): array {
    $serverId = $server->id();
    $backend = $this->getBackend($server);

    $filterPattern = $server->getModelFilter() ?: '';
    $discovered = [];

    foreach ($backend->listModels($server) as $modelEntry) {
      $rawId = $modelEntry['id'];
      if ($filterPattern !== '' && !ModelFilter::matches($rawId, $filterPattern)) {
        continue;
      }
      $machine = $this->getMachineName($rawId);
      $entityId = $this->buildModelEntityId($serverId, $machine);

      $metadata = $backend->detectModelMetadata($modelEntry);
      // Prefill tier/costs from definitions/model_defaults.yml when the
      // backend detects none; applyDetectedMetadata() still never
      // overwrites a manual edit.
      if (!isset($metadata['quality_tier']) && ($tier = ModelDefaults::guessTier($rawId)) !== NULL) {
        $metadata['quality_tier'] = $tier;
      }
      $metadata += ModelDefaults::guessCosts($rawId);
      if (($sampling = ModelDefaults::guessSampling($rawId)) !== []) {
        $metadata['sampling'] = $sampling;
      }

      $discovered[$entityId] = [
        'raw' => $rawId,
        'detected' => $backend->detectOperationTypes($modelEntry),
        'metadata' => $metadata,
      ];
    }

    // Let subscribers enrich or correct the set before persistence
    // (site pricing, tier policies, dropping models).
    $event = new ModelsDiscoveredEvent($serverId, $discovered);
    $this->eventDispatcher->dispatch($event, ModelsDiscoveredEvent::EVENT_NAME);
    $discovered = $event->getModels();

    $this->persistModelsForServer($serverId, $discovered);

    return $this->getModelsForServer($serverId);
  }

  /**
   * Instantiates the backend plugin configured on a server.
   */
  public function getBackend(AiUniversalServerInterface $server): AiServerBackendInterface {
    /** @var \Drupal\ai_provider_universal\Backend\AiServerBackendInterface $backend */
    $backend = $this->backendManager->createInstance($server->getBackend());
    return $backend;
  }

  /**
   * Returns configured models for a server (or all if no serverId).
   */
  public function getModelsForServer(?string $serverId = NULL, ?string $operationType = NULL): array {
    $storage = $this->entityTypeManager->getStorage('ai_universal_model');
    $query = $storage->getQuery();

    if ($serverId) {
      $query->condition('server_id', $serverId);
    }

    $ids = $query->accessCheck(FALSE)->execute();
    $models = $storage->loadMultiple($ids);

    $result = [];

    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model */
    foreach ($models as $model) {
      $key = $model->id();
      $raw = $model->getRawModelId();

      $effective = $model->getEffectiveOperationTypes();
      if ($operationType === NULL || in_array($operationType, $effective, TRUE)) {
        $result[$key] = $raw;
      }
    }

    return $result;
  }

  /**
   * Returns models grouped by server, for optgroup-style select options.
   *
   * The outer keys are server labels (used as <optgroup> labels), the inner
   * arrays map model entity id => raw model id. This keeps the AI settings
   * model dropdown unambiguous when several servers expose the same raw model
   * id, without prefixing every option with a long server name.
   *
   * @param string|null $operationType
   *   Optional operation type to filter models by.
   *
   * @return array<string, array<string, string>>
   *   Map of server label => [model entity id => raw model id].
   */
  public function getModelsGroupedByServer(?string $operationType = NULL): array {
    $storage = $this->entityTypeManager->getStorage('ai_universal_model');
    $serverStorage = $this->entityTypeManager->getStorage('ai_universal_server');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();

    $grouped = [];

    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model */
    foreach ($storage->loadMultiple($ids) as $model) {
      $effective = $model->getEffectiveOperationTypes();
      if ($operationType !== NULL && !in_array($operationType, $effective, TRUE)) {
        continue;
      }
      $serverId = $model->getServerId();
      $server = $serverStorage->load($serverId);
      $groupLabel = $server ? $server->label() : $serverId;
      $grouped[$groupLabel][$model->id()] = $model->getRawModelId();
    }

    return $grouped;
  }

  /**
   * Persists discovered models and cleans up removed ones.
   */
  protected function persistModelsForServer(string $serverId, array $discovered): void {
    $storage = $this->entityTypeManager->getStorage('ai_universal_model');
    $existing = $storage->loadByProperties(['server_id' => $serverId]);
    $seen = [];

    foreach ($discovered as $entityId => $info) {
      $seen[$entityId] = TRUE;

      /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model */
      $model = $storage->load($entityId) ?: $storage->create(['id' => $entityId]);

      $raw = $info['raw'];
      $detected = $info['detected'];

      $model->setRawModelId($raw);
      $model->setServerId($serverId);
      $model->setDetectedOperationTypes($detected);
      $this->applyDetectedMetadata($model, $info['metadata'] ?? []);

      $server = $this->entityTypeManager->getStorage('ai_universal_server')->load($serverId);
      $serverLabel = $server?->label() ?? '';
      $niceLabel = $serverLabel ? ($serverLabel . ' / ' . $raw) : $raw;

      if (empty($model->label()) || $model->label() === $raw || str_ends_with($model->label(), ' / ' . $raw)) {
        $model->set('label', $niceLabel);
      }

      $model->save();
    }

    foreach ($existing as $oldId => $oldModel) {
      if (!isset($seen[$oldId])) {
        $oldModel->delete();
      }
    }
  }

  /**
   * Applies backend-detected metadata to the model entity.
   *
   * Cost / quality / context are only written while still NULL so re-discovery
   * never clobbers manual edits. Supported features are always refreshed:
   * they are pure catalog flags with no UI override.
   */
  protected function applyDetectedMetadata($model, array $metadata): void {
    if ($model->getCostInput() === NULL && isset($metadata['cost_input'])) {
      $model->setCostInput((float) $metadata['cost_input']);
    }
    if ($model->getCostOutput() === NULL && isset($metadata['cost_output'])) {
      $model->setCostOutput((float) $metadata['cost_output']);
    }
    if ($model->getQualityTier() === NULL && isset($metadata['quality_tier'])) {
      $model->setQualityTier((int) $metadata['quality_tier']);
    }
    if ($model->getContextLength() === NULL && isset($metadata['context_length'])) {
      $model->setContextLength((int) $metadata['context_length']);
    }
    // Always rewrite: missing key means the backend reported none.
    $model->setSupportedFeatures($metadata['supported_features'] ?? []);
    if ($model->getSampling() === [] && isset($metadata['sampling'])) {
      $model->setSampling($metadata['sampling']);
    }
  }

  /**
   * Builds a stable entity ID "server__machine".
   */
  public function buildModelEntityId(string $serverId, string $machineName): string {
    $cleanServer = $this->sanitizeForId($serverId);
    $cleanMachine = $this->sanitizeForId($machineName);

    if ($cleanMachine === '') {
      $cleanMachine = 'model';
    }
    if ($cleanServer === '') {
      $cleanServer = 'server';
    }

    $id = $cleanServer . '__' . $cleanMachine;

    $max = 160;
    if (strlen($id) > $max) {
      $suffix = '_' . substr(hash('sha256', $id), 0, 8);
      $id = substr($id, 0, $max - strlen($suffix)) . $suffix;
    }
    return $id;
  }

  /**
   * Reduces a string to a safe config id fragment ([a-z0-9_], collapsed).
   */
  protected function sanitizeForId(string $value): string {
    $value = preg_replace('@[^a-z0-9_]+@', '_', mb_strtolower($value));
    return trim(preg_replace('@_+@', '_', $value), '_');
  }

  /**
   * Generates a machine name.
   */
  public function getMachineName(string $string): string {
    $trans = $this->transliteration->transliterate($string, LanguageInterface::LANGCODE_DEFAULT, '_');
    $trans = mb_strtolower($trans);
    $machine = (string) preg_replace('@[^a-z0-9_]+@', '_', $trans);
    return trim((string) preg_replace('@_+@', '_', $machine), '_');
  }

}
