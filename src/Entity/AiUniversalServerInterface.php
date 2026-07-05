<?php

namespace Drupal\ai_provider_universal\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for universal server config entities.
 */
interface AiUniversalServerInterface extends ConfigEntityInterface {

  /**
   * Gets the backend plugin id (protocol) for this server.
   *
   * Defaults to 'openai_compatible'.
   */
  public function getBackend(): string;

  /**
   * Gets the host name (including protocol).
   */
  public function getHostName(): string;

  /**
   * Gets the port.
   */
  public function getPort(): string;

  /**
   * Gets the Key entity ID for API authentication, if any.
   *
   * Empty string means no authentication (e.g. local llama.cpp).
   */
  public function getApiKey(): string;

  /**
   * Gets the request timeout in seconds.
   */
  public function getTimeout(): int;

  /**
   * Gets the manually configured operation types.
   *
   * @return string[]
   *   Operation type IDs, or empty array for auto-detection.
   */
  public function getOperationTypes(): array;

  /**
   * Gets the model filter pattern.
   *
   * @return string
   *   Glob-style or simple string filter pattern.
   */
  public function getModelFilter(): string;

  /**
   * Gets the maximum requests per day across all models (NULL = unlimited).
   */
  public function getDailyRequestLimit(): ?int;

  /**
   * Sets the maximum requests per day (NULL = unlimited).
   */
  public function setDailyRequestLimit(?int $limit): self;

  /**
   * Gets the maximum tokens (input + output) per day (NULL = unlimited).
   */
  public function getDailyTokenLimit(): ?int;

  /**
   * Sets the maximum tokens per day (NULL = unlimited).
   */
  public function setDailyTokenLimit(?int $limit): self;

  /**
   * Gets the usage % that triggers an alert (NULL = no alert).
   */
  public function getAlertThreshold(): ?int;

  /**
   * Sets the alert threshold percentage (NULL = no alert).
   */
  public function setAlertThreshold(?int $percent): self;

  /**
   * Gets the % the server may exceed its limits before blocking.
   */
  public function getLimitGrace(): ?int;

  /**
   * Sets the grace percentage (0/NULL = block exactly at the limit).
   */
  public function setLimitGrace(?int $percent): self;

}
