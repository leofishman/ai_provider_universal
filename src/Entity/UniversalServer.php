<?php

namespace Drupal\ai_provider_universal\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_universal\Form\UniversalServerDeleteForm;
use Drupal\ai_provider_universal\Form\UniversalServerForm;
use Drupal\ai_provider_universal\UniversalServerListBuilder;

/**
 * Defines a configured OpenAI-compatible server instance.
 */
#[ConfigEntityType(
  id: 'universal_server',
  label: new TranslatableMarkup('OpenAI-compatible Server'),
  label_collection: new TranslatableMarkup('Servers'),
  label_singular: new TranslatableMarkup('server'),
  label_plural: new TranslatableMarkup('servers'),
  handlers: [
    'list_builder' => UniversalServerListBuilder::class,
    'form' => [
      'add' => UniversalServerForm::class,
      'edit' => UniversalServerForm::class,
      'delete' => UniversalServerDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  config_prefix: 'server',
  admin_permission: 'administer ai providers',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  config_export: [
    'id',
    'label',
    'backend',
    'host_name',
    'port',
    'api_key',
    'timeout',
    'operation_types',
    'model_filter',
    'daily_request_limit',
    'daily_token_limit',
    'alert_threshold',
    'limit_grace',
  ],
  links: [
    'add-form' => '/admin/config/ai/providers/universal/add',
    'edit-form' => '/admin/config/ai/providers/universal/{universal_server}',
    'delete-form' => '/admin/config/ai/providers/universal/{universal_server}/delete',
    'collection' => '/admin/config/ai/providers/universal',
  ],
)]
class UniversalServer extends ConfigEntityBase implements UniversalServerInterface {

  /**
   * The server machine name.
   *
   * @var string
   */
  protected string $id;

  /**
   * The human-readable server label.
   *
   * @var string
   */
  protected string $label;

  /**
   * The backend plugin id (protocol) used to talk to this server.
   *
   * @var string
   */
  protected string $backend = 'openai_compatible';

  /**
   * The host name with protocol.
   *
   * @var string
   */
  protected string $host_name = '';

  /**
   * The port number.
   *
   * @var string
   */
  protected string $port = '';

  /**
   * Optional Key entity ID for authenticated servers.
   *
   * @var string
   */
  protected string $api_key = '';

  /**
   * Request timeout in seconds.
   *
   * @var int
   */
  protected int $timeout = 600;

  /**
   * Manually configured operation types (empty = auto-detect).
   *
   * @var string[]
   */
  protected array $operation_types = [];

  /**
   * Model filtering pattern (comma-separated globs/sub-strings).
   *
   * @var string
   */
  protected string $model_filter = '';

  /**
   * Maximum requests per day across all models. NULL = unlimited.
   *
   * Enforced by the router submodule; without it the value is informational.
   *
   * @var int|null
   */
  protected ?int $daily_request_limit = NULL;

  /**
   * Maximum tokens (input + output) per day across all models. NULL = none.
   *
   * @var int|null
   */
  protected ?int $daily_token_limit = NULL;

  /**
   * Usage percentage that triggers an alert event/log. NULL = no alert.
   *
   * @var int|null
   */
  protected ?int $alert_threshold = 80;

  /**
   * Percentage the server may exceed its limits before blocking. 0/NULL = none.
   *
   * @var int|null
   */
  protected ?int $limit_grace = NULL;

  /**
   * {@inheritdoc}
   */
  public function getAlertThreshold(): ?int {
    return $this->alert_threshold;
  }

  /**
   * {@inheritdoc}
   */
  public function setAlertThreshold(?int $percent): self {
    $this->alert_threshold = $percent;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getLimitGrace(): ?int {
    return $this->limit_grace;
  }

  /**
   * {@inheritdoc}
   */
  public function setLimitGrace(?int $percent): self {
    $this->limit_grace = $percent;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDailyRequestLimit(): ?int {
    return $this->daily_request_limit;
  }

  /**
   * {@inheritdoc}
   */
  public function setDailyRequestLimit(?int $limit): self {
    $this->daily_request_limit = $limit;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDailyTokenLimit(): ?int {
    return $this->daily_token_limit;
  }

  /**
   * {@inheritdoc}
   */
  public function setDailyTokenLimit(?int $limit): self {
    $this->daily_token_limit = $limit;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getBackend(): string {
    return $this->backend ?: 'openai_compatible';
  }

  /**
   * {@inheritdoc}
   */
  public function getHostName(): string {
    return $this->host_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getPort(): string {
    return $this->port;
  }

  /**
   * {@inheritdoc}
   */
  public function getApiKey(): string {
    return $this->api_key;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimeout(): int {
    return $this->timeout ?: 600;
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
  public function getModelFilter(): string {
    return $this->model_filter ?? '';
  }

}
