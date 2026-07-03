<?php

namespace Drupal\ai_provider_universal_router\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Fired when a server's usage crosses its alert threshold or its limit.
 *
 * Dispatched at most once per server and day for each event name. Subscribe
 * to react (mail, Slack, ECA, disable content flows...) when a provider is
 * close to — or past — its daily budget.
 */
class UsageThresholdEvent extends Event {

  /**
   * Usage crossed the server's alert threshold percentage.
   */
  const ALERT = 'ai_provider_universal_router.usage_alert';

  /**
   * Usage exhausted the server's limit (including any grace).
   */
  const EXHAUSTED = 'ai_provider_universal_router.usage_exhausted';

  /**
   * Constructs the event.
   *
   * @param string $serverId
   *   The universal_server entity id.
   * @param string $metric
   *   Which limit was crossed: 'requests' or 'tokens'.
   * @param int $usage
   *   Today's usage for that metric.
   * @param int $limit
   *   The configured daily limit for that metric.
   */
  public function __construct(
    public readonly string $serverId,
    public readonly string $metric,
    public readonly int $usage,
    public readonly int $limit,
  ) {}

}
