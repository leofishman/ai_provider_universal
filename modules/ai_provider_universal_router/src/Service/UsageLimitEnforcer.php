<?php

namespace Drupal\ai_provider_universal_router\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ai_provider_universal\Entity\UniversalServerInterface;
use Drupal\ai_provider_universal\Service\UsageTracker;
use Drupal\ai_provider_universal_router\Event\UsageThresholdEvent;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Decides whether a server has exhausted its daily usage limits.
 *
 * Limits are per server (that is where the account/budget lives): when a
 * server is over limit the router drops all its models from the candidate
 * pool and fails over to another provider, and the main provider rejects
 * direct calls. Lives in the router submodule so limit enforcement can be
 * turned off with it; the main module reaches it through the optional
 * 'ai_provider_universal_router.limits' alias.
 *
 * Two per-server thresholds refine the hard limit:
 * - alert_threshold (%): crossing it logs a warning and dispatches
 *   UsageThresholdEvent::ALERT, once per server and day.
 * - limit_grace (%): usage may exceed the limit by up to this percentage
 *   before blocking (0/NULL blocks exactly at the limit). Exhaustion
 *   dispatches UsageThresholdEvent::EXHAUSTED, once per server and day.
 */
class UsageLimitEnforcer {

  /**
   * Per-request verdict cache, keyed by server id.
   *
   * The router evaluates every candidate model in a loop; without this each
   * model of the same server would repeat the aggregation query.
   *
   * @var array<string, bool>
   */
  protected array $verdicts = [];

  public function __construct(
    protected UsageTracker $usageTracker,
    protected EventDispatcherInterface $eventDispatcher,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether a server has exhausted any of its daily limits (grace included).
   */
  public function isServerOverLimit(UniversalServerInterface $server): bool {
    $limits = array_filter([
      'requests' => $server->getDailyRequestLimit(),
      'tokens' => $server->getDailyTokenLimit(),
    ], static fn (?int $l) => $l !== NULL);
    if (!$limits) {
      return FALSE;
    }

    $id = (string) $server->id();
    if (isset($this->verdicts[$id])) {
      return $this->verdicts[$id];
    }

    $usage = $this->usageTracker->getTodayForServer($id);
    $used = [
      'requests' => $usage['requests'],
      'tokens' => $usage['input_tokens'] + $usage['output_tokens'],
    ];

    $grace = $server->getLimitGrace() ?? 0;
    $alertThreshold = $server->getAlertThreshold();
    $over = FALSE;
    foreach ($limits as $metric => $limit) {
      // Effective ceiling: the limit stretched by the grace percentage.
      $ceiling = $limit + (int) floor($limit * $grace / 100);
      if ($used[$metric] >= $ceiling) {
        $over = TRUE;
        $this->notifyOnce(UsageThresholdEvent::EXHAUSTED, $id, $metric, $used[$metric], $limit);
      }
      elseif ($alertThreshold !== NULL && $used[$metric] >= $limit * $alertThreshold / 100) {
        $this->notifyOnce(UsageThresholdEvent::ALERT, $id, $metric, $used[$metric], $limit);
      }
    }

    return $this->verdicts[$id] = $over;
  }

  /**
   * Logs and dispatches a threshold event, at most once per server and day.
   */
  protected function notifyOnce(string $eventName, string $server_id, string $metric, int $usage, int $limit): void {
    $key = $eventName . '.' . $server_id;
    $today = (int) date('Ymd', $this->time->getRequestTime());
    if ($this->state->get($key) === $today) {
      return;
    }
    $this->state->set($key, $today);

    $this->logger->warning('Server @server @what its daily @metric limit: @usage of @limit.', [
      '@server' => $server_id,
      '@what' => $eventName === UsageThresholdEvent::EXHAUSTED ? 'exhausted' : 'is nearing',
      '@metric' => $metric,
      '@usage' => $usage,
      '@limit' => $limit,
    ]);
    $this->eventDispatcher->dispatch(new UsageThresholdEvent($server_id, $metric, $usage, $limit), $eventName);
  }

}
