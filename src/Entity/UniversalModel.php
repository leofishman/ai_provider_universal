<?php

namespace Drupal\ai_provider_universal\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a discovered/configured model on an OpenAI-compatible server.
 *
 * Replaces State-based storage (ai_provider_universal.server.*.models etc).
 */
#[ConfigEntityType(
  id: 'universal_model',
  label: new TranslatableMarkup('AI Model'),
  label_collection: new TranslatableMarkup('Models'),
  label_singular: new TranslatableMarkup('model'),
  label_plural: new TranslatableMarkup('models'),
  handlers: [
    // Models are primarily auto-managed via server discovery and the server
    // edit form. A dedicated admin UI can be added later.
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  config_prefix: 'model',
  admin_permission: 'administer ai providers',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  config_export: [
    'id',
    'label',
    'server_id',
    'raw_model_id',
    'detected_operation_types',
    'operation_types',
  ],
  links: [
    'collection' => '/admin/config/ai/providers/universal/models',
    // edit/delete optional for v1 of this refactor.
  ],
)]
class UniversalModel extends ConfigEntityBase implements UniversalModelInterface {

  /**
   * The model machine name (unique, typically server_id + sanitized raw id).
   *
   * @var string
   */
  protected string $id;

  /**
   * Human label (usually the raw model id or "Server / model").
   *
   * @var string
   */
  protected string $label;

  /**
   * Owning server ID (references universal_server.id).
   *
   * @var string
   */
  protected string $server_id = '';

  /**
   * The identifier returned by /v1/models (sent to the API).
   *
   * @var string
   */
  protected string $raw_model_id = '';

  /**
   * Auto-detected types (from detectOperationTypes).
   *
   * @var string[]
   */
  protected array $detected_operation_types = [];

  /**
   * User overrides. If non-empty, these take precedence.
   *
   * @var string[]
   */
  protected array $operation_types = [];

  /**
   * {@inheritdoc}
   */
  public function getServerId(): string {
    return $this->server_id ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getRawModelId(): string {
    return $this->raw_model_id ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getDetectedOperationTypes(): array {
    return $this->detected_operation_types ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getOperationTypes(): array {
    return $this->operation_types ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setOperationTypes(array $types): self {
    $this->operation_types = array_values(array_filter($types));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getEffectiveOperationTypes(): array {
    $overrides = $this->getOperationTypes();
    if (!empty($overrides)) {
      return $overrides;
    }
    return $this->getDetectedOperationTypes() ?: ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function setDetectedOperationTypes(array $types): self {
    $this->detected_operation_types = array_values(array_filter($types));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setRawModelId(string $raw_id): self {
    $this->raw_model_id = $raw_id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setServerId(string $server_id): self {
    $this->server_id = $server_id;
    return $this;
  }

}
