<?php

namespace Drupal\ai_provider_universal\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched after discovery detection, before models are persisted.
 *
 * Subscribers can enrich or correct the discovered set — inject site
 * pricing, adjust quality tiers, drop models — without writing a backend
 * plugin. Changes made here flow through the normal persistence rules
 * (manual UI edits are still never clobbered).
 */
class ModelsDiscoveredEvent extends Event {

  const EVENT_NAME = 'ai_provider_universal.models_discovered';

  public function __construct(
    protected string $serverId,
    protected array $models,
  ) {
  }

  /**
   * The ai_universal_server entity id being discovered.
   */
  public function getServerId(): string {
    return $this->serverId;
  }

  /**
   * The discovered set, keyed by model entity id.
   *
   * @return array<string, array{raw: string, detected: string[], metadata: array}>
   *   Per model: raw id, detected operation types, routing metadata
   *   (cost_input/cost_output/quality_tier/context_length/
   *   supported_features/sampling).
   */
  public function getModels(): array {
    return $this->models;
  }

  /**
   * Replaces the discovered set (same shape as getModels()).
   */
  public function setModels(array $models): void {
    $this->models = $models;
  }

}
