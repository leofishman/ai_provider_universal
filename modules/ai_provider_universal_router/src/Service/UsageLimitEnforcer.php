<?php

namespace Drupal\ai_provider_universal_router\Service;

use Drupal\ai_provider_universal\Entity\UniversalServerInterface;
use Drupal\ai_provider_universal\Service\UsageTracker;

/**
 * Decides whether a server has exhausted its daily usage limits.
 *
 * Limits are per server (that is where the account/budget lives): when a
 * server is over limit the router drops all its models from the candidate
 * pool and fails over to another provider, and the main provider rejects
 * direct calls. Lives in the router submodule so limit enforcement can be
 * turned off with it; the main module reaches it through the optional
 * 'ai_provider_universal_router.limits' alias.
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
  ) {}

  /**
   * Whether a server has exhausted any of its daily limits.
   */
  public function isServerOverLimit(UniversalServerInterface $server): bool {
    $requestLimit = $server->getDailyRequestLimit();
    $tokenLimit = $server->getDailyTokenLimit();
    if ($requestLimit === NULL && $tokenLimit === NULL) {
      return FALSE;
    }

    $id = (string) $server->id();
    if (isset($this->verdicts[$id])) {
      return $this->verdicts[$id];
    }

    $usage = $this->usageTracker->getTodayForServer($id);
    $over = ($requestLimit !== NULL && $usage['requests'] >= $requestLimit)
      || ($tokenLimit !== NULL && $usage['input_tokens'] + $usage['output_tokens'] >= $tokenLimit);

    return $this->verdicts[$id] = $over;
  }

}
