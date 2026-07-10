<?php

namespace Drupal\ai_provider_universal\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched after every successful chat call to a concrete model.
 *
 * Read-only companion to ModelPreCallEvent: subscribers get the model,
 * token usage and wall-clock latency for custom telemetry, cost alerting
 * or dashboards. Failed calls do not dispatch this event — subscribe to
 * the AI core's AiExceptionEvent for those.
 */
class ModelPostCallEvent extends Event {

  const EVENT_NAME = 'ai_provider_universal.model_post_call';

  public function __construct(
    protected string $modelId,
    protected string $operationType,
    protected ?int $inputTokens,
    protected ?int $outputTokens,
    protected float $latencyMs,
  ) {
  }

  /**
   * The ai_universal_model entity id that served the call.
   */
  public function getModelId(): string {
    return $this->modelId;
  }

  /**
   * The AI operation type (chat, ...).
   */
  public function getOperationType(): string {
    return $this->operationType;
  }

  /**
   * Input tokens reported by the server, NULL when not reported.
   */
  public function getInputTokens(): ?int {
    return $this->inputTokens;
  }

  /**
   * Output tokens reported by the server, NULL when not reported.
   */
  public function getOutputTokens(): ?int {
    return $this->outputTokens;
  }

  /**
   * Wall-clock duration of the call in milliseconds.
   */
  public function getLatencyMs(): float {
    return $this->latencyMs;
  }

}
