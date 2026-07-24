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
  id: 'ai_universal_model',
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
    'cost_input',
    'cost_output',
    'quality_tier',
    'context_length',
    'reasoning',
    'supported_features',
    'sampling',
    'extra_params',
  ],
  links: [
    'collection' => '/admin/config/ai/providers/universal/models',
    // edit/delete optional for v1 of this refactor.
  ],
)]
class AiUniversalModel extends ConfigEntityBase implements AiUniversalModelInterface {

  /**
   * The model machine name (unique, typically server_id + sanitized raw id).
   *
   * Nullable so EntityBase::createDuplicate() can blank it: duplicating a
   * model is the supported way to run one model under two configurations.
   *
   * @var string|null
   */
  protected ?string $id = NULL;

  /**
   * Human label (usually the raw model id or "Server / model").
   *
   * @var string
   */
  protected string $label;

  /**
   * Owning server ID (references ai_universal_server.id).
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
   * Cost in USD per million input tokens. NULL = unknown.
   *
   * Local models are typically 0. Used by smart routing to pick the cheapest
   * capable model.
   *
   * @var float|null
   */
  protected ?float $cost_input = NULL;

  /**
   * Cost in USD per million output tokens. NULL = unknown.
   *
   * @var float|null
   */
  protected ?float $cost_output = NULL;

  /**
   * Subjective quality tier, 1 (lowest) to 5 (frontier). NULL = unrated.
   *
   * @var int|null
   */
  protected ?int $quality_tier = NULL;

  /**
   * Maximum context length in tokens. NULL = unknown.
   *
   * @var int|null
   */
  protected ?int $context_length = NULL;

  /**
   * Reasoning effort override: none/low/medium/high. NULL = server default.
   *
   * Translated to the OpenAI-compatible reasoning_effort request parameter
   * by the provider; NULL sends nothing so the server/model default applies.
   * Distinct from the "reasoning" entry in supported_features, which only
   * means the catalog says the model can reason.
   *
   * @var string|null
   */
  protected ?string $reasoning = NULL;

  /**
   * Catalog-reported capability flags (tools, json_mode, reasoning, ...).
   *
   * @var string[]
   */
  protected array $supported_features = [];

  /**
   * Sampling parameter overrides sent on chat requests.
   *
   * @var array<string, float>
   */
  protected array $sampling = [];

  /**
   * Free-form request parameters merged into every chat payload.
   *
   * @var array<string, mixed>
   */
  protected array $extra_params = [];

  /**
   * Request parameters extra_params may never set (owned by the provider).
   */
  protected const RESERVED_PARAMS = [
    'model',
    'messages',
    'stream',
    'stream_options',
  ];

  /**
   * Sampling keys accepted by setSampling(), OpenAI-compatible names.
   */
  protected const SAMPLING_KEYS = [
    'temperature',
    'top_p',
    'frequency_penalty',
    'presence_penalty',
  ];

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

  /**
   * {@inheritdoc}
   */
  public function getCostInput(): ?float {
    return $this->cost_input;
  }

  /**
   * {@inheritdoc}
   */
  public function setCostInput(?float $cost): self {
    $this->cost_input = $cost;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCostOutput(): ?float {
    return $this->cost_output;
  }

  /**
   * {@inheritdoc}
   */
  public function setCostOutput(?float $cost): self {
    $this->cost_output = $cost;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getQualityTier(): ?int {
    return $this->quality_tier;
  }

  /**
   * {@inheritdoc}
   */
  public function setQualityTier(?int $tier): self {
    $this->quality_tier = $tier === NULL ? NULL : max(1, min(5, $tier));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextLength(): ?int {
    return $this->context_length;
  }

  /**
   * {@inheritdoc}
   */
  public function setContextLength(?int $length): self {
    $this->context_length = $length;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getReasoning(): ?string {
    return $this->reasoning;
  }

  /**
   * {@inheritdoc}
   */
  public function setReasoning(?string $reasoning): self {
    $allowed = ['none', 'low', 'medium', 'high'];
    $this->reasoning = in_array($reasoning, $allowed, TRUE) ? $reasoning : NULL;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures(): array {
    return $this->supported_features ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setSupportedFeatures(array $features): self {
    $normalized = [];
    foreach ($features as $feature) {
      if (!is_string($feature)) {
        continue;
      }
      $feature = strtolower(trim($feature));
      if ($feature !== '') {
        $normalized[$feature] = $feature;
      }
    }
    ksort($normalized);
    $this->supported_features = array_values($normalized);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsFeature(string $feature): bool {
    return in_array(strtolower($feature), $this->getSupportedFeatures(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getSampling(): array {
    return $this->sampling ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setSampling(array $sampling): self {
    $normalized = [];
    foreach (self::SAMPLING_KEYS as $key) {
      if (isset($sampling[$key]) && is_numeric($sampling[$key])) {
        $normalized[$key] = (float) $sampling[$key];
      }
    }
    $this->sampling = $normalized;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtraParams(): array {
    return $this->extra_params ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function setExtraParams(array $params): self {
    // Anything else is deliberately allowed through: the whole point is
    // sending parameters this module knows nothing about. Only the keys the
    // provider itself owns are protected, so a stored value can never
    // redirect a call to another model, replace the conversation, or turn
    // streaming on behind the response parser's back.
    foreach (self::RESERVED_PARAMS as $reserved) {
      unset($params[$reserved]);
    }
    $this->extra_params = $params;
    return $this;
  }

}
