<?php

namespace Drupal\ai_provider_universal_router\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

/**
 * Remembers which servers just failed to answer, so routing skips them.
 *
 * A circuit breaker rather than a health check: probing every server before
 * every decision costs a request on the happy path, when almost always
 * nothing is wrong. Instead the provider reports the failures it already
 * hits, and the router drops that server's models from the candidate pool
 * while the mark lasts — the same thing it does for a server that exhausted
 * its daily limit.
 *
 * Only failures another candidate could avoid are recorded: a refused or
 * timed-out connection, a 5xx, a rate limit. A 400 means the request was
 * wrong and would be wrong everywhere.
 *
 * Lives in the router submodule, like limit enforcement; the main provider
 * reaches it through the optional 'ai_provider_universal_router.health'
 * alias and works unchanged without it.
 */
class ServerHealth {

  /**
   * How long a server stays marked down after its first failure, in seconds.
   *
   * Long enough to route around a restart, short enough that a recovered
   * server is tried again without anyone intervening. Each failure in a row
   * doubles it, up to MAX_DOWN_TTL.
   */
  protected const DOWN_TTL = 60;

  /**
   * Ceiling for the doubled mark, in seconds.
   */
  protected const MAX_DOWN_TTL = 960;

  /**
   * Cache id prefix for the marks.
   */
  protected const CID_PREFIX = 'ai_provider_universal_router:down:';

  public function __construct(
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Marks a server as unreachable, for longer on each failure in a row.
   *
   * 60s, 120s, 240s ... up to MAX_DOWN_TTL. A failure counts as "in a row"
   * when it comes within one mark's length after the previous mark expired
   * — the server came back and fell over again. After that quiet window the
   * count starts over, so nothing has to be written on the happy path.
   *
   * @param string $serverId
   *   The ai_universal_server entity id.
   * @param string $reason
   *   Short reason, logged once per mark.
   */
  public function markDown(string $serverId, string $reason = ''): void {
    if ($this->isDown($serverId)) {
      return;
    }
    $previous = $this->cache->get(self::CID_PREFIX . $serverId);
    $failures = ($previous->data['failures'] ?? 0) + 1;
    $ttl = min(self::DOWN_TTL * 2 ** ($failures - 1), self::MAX_DOWN_TTL);
    $until = $this->time->getRequestTime() + $ttl;
    // The item outlives the mark by one mark's length, to remember the count.
    $this->cache->set(self::CID_PREFIX . $serverId, [
      'until' => $until,
      'failures' => $failures,
    ], $until + $ttl);
    $this->logger->warning('Server @server is being skipped by smart routing for @ttl seconds (failure @n in a row): @reason', [
      '@server' => $serverId,
      '@ttl' => $ttl,
      '@n' => $failures,
      '@reason' => $reason ?: 'unreachable',
    ]);
  }

  /**
   * Whether a server is currently marked down.
   */
  public function isDown(string $serverId): bool {
    $item = $this->cache->get(self::CID_PREFIX . $serverId);
    return ($item->data['until'] ?? 0) > $this->time->getRequestTime();
  }

  /**
   * Clears the mark, e.g. after the server answered again.
   */
  public function markUp(string $serverId): void {
    $this->cache->delete(self::CID_PREFIX . $serverId);
  }

}
