<?php

namespace Drupal\ai_provider_universal\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched before every inference call to a concrete model.
 *
 * Subscribers can block the call (custom quota schemes, business hours,
 * compliance rules) or swap the model for another one. Dispatched after
 * smart-route resolution, so the model id is always a concrete
 * ai_universal_model entity id.
 */
class ModelPreCallEvent extends Event {

  const EVENT_NAME = 'ai_provider_universal.model_pre_call';

  /**
   * Reason the call was blocked, NULL while not blocked.
   */
  protected ?string $blockReason = NULL;

  public function __construct(
    protected string $modelId,
    protected readonly string $operationType,
  ) {}

  /**
   * Gets the ai_universal_model entity id the call will use.
   */
  public function getModelId(): string {
    return $this->modelId;
  }

  /**
   * Swaps the call to another model (an ai_universal_model entity id).
   */
  public function setModelId(string $model_id): void {
    $this->modelId = $model_id;
  }

  /**
   * Gets the operation type (chat, embeddings, rerank, ...).
   */
  public function getOperationType(): string {
    return $this->operationType;
  }

  /**
   * Blocks the call; the provider throws AiRequestErrorException.
   */
  public function block(string $reason): void {
    $this->blockReason = $reason;
    $this->stopPropagation();
  }

  /**
   * Whether a subscriber blocked the call.
   */
  public function isBlocked(): bool {
    return $this->blockReason !== NULL;
  }

  /**
   * Gets the block reason, NULL while not blocked.
   */
  public function getBlockReason(): ?string {
    return $this->blockReason;
  }

}
