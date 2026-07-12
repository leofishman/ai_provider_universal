<?php

namespace Drupal\ai_provider_universal_router\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for smart route config entities.
 */
interface AiUniversalRouteInterface extends ConfigEntityInterface {

  /**
   * Gets the operation type this route serves (chat, embeddings, ...).
   */
  public function getOperationType(): string;

  /**
   * Gets the candidate ai_universal_model entity ids.
   *
   * @return string[]
   *   Candidate model ids. Empty = every model supporting the operation.
   */
  public function getCandidates(): array;

  /**
   * Minimum quality tier for prompts classified as simple.
   */
  public function getSimpleTier(): int;

  /**
   * Minimum quality tier for prompts classified as complex.
   */
  public function getComplexTier(): int;

  /**
   * Model entity id that judges answers before returning ('' = disabled).
   *
   * One yes/no verification call (typically to a free local model); on
   * rejection the request escalates to the best candidate. Lighter than
   * the fact-check cascade and independent of the factcheck module.
   */
  public function getVerifierModel(): string;

  /**
   * Whether chat answers on this route are fact-checked.
   *
   * Requires the ai_provider_universal_factcheck module; ignored otherwise.
   */
  public function isFactcheckEnabled(): bool;

  /**
   * Minimum support score in [0, 1]; below it the request escalates.
   */
  public function getFactcheckMinScore(): float;

  /**
   * AI core Guardrail set id for this route's calls ('' = provider default).
   *
   * Only attached when the caller provided no set of its own.
   */
  public function getGuardrailSet(): string;

  /**
   * Catalog features every candidate must report (empty = no filter).
   *
   * Matched against the model's discovered supported_features (tools,
   * json_mode, reasoning, ...). Models on servers that publish no
   * features are excluded when this is set.
   *
   * @return string[]
   *   Lowercase feature ids.
   */
  public function getRequiredFeatures(): array;

}
