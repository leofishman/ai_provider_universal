<?php

namespace Drupal\ai_provider_universal_router\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for smart route config entities.
 */
interface UniversalRouteInterface extends ConfigEntityInterface {

  /**
   * Gets the operation type this route serves (chat, embeddings, ...).
   */
  public function getOperationType(): string;

  /**
   * Gets the candidate universal_model entity ids.
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

}
