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
   * How long a server stays marked down, in seconds.
   *
   * Long enough to route around a restart, short enough that a recovered
   * server is tried again without anyone intervening.
   */
  protected const DOWN_TTL = 60;

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
   * Marks a server as unreachable for the next DOWN_TTL seconds.
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
    $this->cache->set(
      self::CID_PREFIX . $serverId,
      TRUE,
      $this->time->getRequestTime() + self::DOWN_TTL,
    );
    $this->logger->warning('Server @server is being skipped by smart routing for @ttl seconds: @reason', [
      '@server' => $serverId,
      '@ttl' => self::DOWN_TTL,
      '@reason' => $reason ?: 'unreachable',
    ]);
  }

  /**
   * Whether a server is currently marked down.
   */
  public function isDown(string $serverId): bool {
    return (bool) $this->cache->get(self::CID_PREFIX . $serverId);
  }

  /**
   * Clears the mark, e.g. after the server answered again.
   */
  public function markUp(string $serverId): void {
    $this->cache->delete(self::CID_PREFIX . $serverId);
  }

}
