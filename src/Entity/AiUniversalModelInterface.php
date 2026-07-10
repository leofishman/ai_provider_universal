<?php

namespace Drupal\ai_provider_universal\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for universal model config entities.
 *
 * Models are discovered from servers and stored as first-class config entities
 * (instead of State). This enables Views, export, per-model config, etc.
 */
interface AiUniversalModelInterface extends ConfigEntityInterface {

  /**
   * Gets the owning server entity ID.
   */
  public function getServerId(): string;

  /**
   * Gets the raw model identifier as reported by the /v1/models endpoint.
   */
  public function getRawModelId(): string;

  /**
   * Gets auto-detected operation types (server flags, HF tags, heuristics).
   *
   * @return string[]
   *   The detected operation type ids.
   */
  public function getDetectedOperationTypes(): array;

  /**
   * Gets the manually overridden operation types for this model.
   *
   * Empty array means "use detected".
   *
   * @return string[]
   *   The manual override operation type ids (empty when none).
   */
  public function getOperationTypes(): array;

  /**
   * Sets manual operation types (overrides). Empty = use detected.
   */
  public function setOperationTypes(array $types): self;

  /**
   * Returns the effective operation types for this model.
   *
   * Prefers manual overrides; falls back to detected.
   *
   * @return string[]
   *   The effective operation type ids.
   */
  public function getEffectiveOperationTypes(): array;

  /**
   * Sets the detected operation types (internal, from discovery).
   */
  public function setDetectedOperationTypes(array $types): self;

  /**
   * Sets the raw model id.
   */
  public function setRawModelId(string $raw_id): self;

  /**
   * Sets the server id this model belongs to.
   */
  public function setServerId(string $server_id): self;

  /**
   * Gets the cost in USD per million input tokens (NULL = unknown).
   *
   * Local/self-hosted models are typically 0. Consumed by smart routing to
   * pick the cheapest capable model.
   */
  public function getCostInput(): ?float;

  /**
   * Sets the cost in USD per million input tokens (NULL = unknown).
   */
  public function setCostInput(?float $cost): self;

  /**
   * Gets the cost in USD per million output tokens (NULL = unknown).
   */
  public function getCostOutput(): ?float;

  /**
   * Sets the cost in USD per million output tokens (NULL = unknown).
   */
  public function setCostOutput(?float $cost): self;

  /**
   * Gets the quality tier, 1 (lowest) to 5 (frontier). NULL = unrated.
   */
  public function getQualityTier(): ?int;

  /**
   * Sets the quality tier (clamped to 1-5, NULL = unrated).
   */
  public function setQualityTier(?int $tier): self;

  /**
   * Gets the maximum context length in tokens (NULL = unknown).
   */
  public function getContextLength(): ?int;

  /**
   * Sets the maximum context length in tokens (NULL = unknown).
   */
  public function setContextLength(?int $length): self;

  /**
   * Gets the reasoning effort override (none/low/medium/high).
   *
   * NULL means "use the server/model default": no reasoning parameter is
   * sent with the request at all.
   */
  public function getReasoning(): ?string;

  /**
   * Sets the reasoning effort override (invalid values become NULL).
   */
  public function setReasoning(?string $reasoning): self;

  /**
   * Gets catalog-reported capability flags (tools, reasoning, json_mode, ...).
   *
   * Populated at discovery from the backend (e.g. Groq supported_features).
   * Empty when the server does not publish features. Re-discovery refreshes
   * the list; there is no manual override field.
   *
   * @return string[]
   *   Normalized feature ids (lowercase).
   */
  public function getSupportedFeatures(): array;

  /**
   * Sets the supported feature flags (internal, from discovery).
   *
   * @param string[] $features
   *   Feature ids as reported by the backend catalog.
   */
  public function setSupportedFeatures(array $features): self;

  /**
   * Whether discovery reported a given capability flag.
   */
  public function supportsFeature(string $feature): bool;

  /**
   * Gets per-model sampling overrides for chat requests.
   *
   * Prefilled at discovery from definitions/model_defaults.yml (vendor
   * documented recommendations) when the family is known; editable per
   * model in the UI. Applied to every chat call, overriding the generic
   * provider configuration; an absent key sends nothing so the server
   * default applies.
   *
   * @return array{temperature?: float, top_p?: float, frequency_penalty?: float, presence_penalty?: float}
   *   Subset of the supported sampling parameters.
   */
  public function getSampling(): array;

  /**
   * Sets the sampling overrides.
   *
   * @param array $sampling
   *   Map of temperature / top_p / frequency_penalty / presence_penalty.
   *   Unknown keys and non-numeric values are dropped.
   */
  public function setSampling(array $sampling): self;

}
