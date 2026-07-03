<?php

namespace Drupal\ai_provider_universal\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Tracks per-model daily usage counters.
 *
 * Counters live in the ai_provider_universal_usage table, one row per model
 * and day, incremented after every request the provider executes. This
 * service is pure accounting; limit *enforcement* (per server) lives in the
 * router submodule's UsageLimitEnforcer.
 */
class UsageTracker {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Records one request against a model's counters for today.
   *
   * @param string $model_id
   *   The universal_model entity id.
   * @param int|null $input_tokens
   *   Input tokens reported by the server, if any.
   * @param int|null $output_tokens
   *   Output tokens reported by the server, if any.
   */
  public function record(string $model_id, ?int $input_tokens, ?int $output_tokens): void {
    try {
      $this->database->merge('ai_provider_universal_usage')
        ->keys(['model_id' => $model_id, 'day' => $this->today()])
        ->fields([
          'requests' => 1,
          'input_tokens' => (int) $input_tokens,
          'output_tokens' => (int) $output_tokens,
        ])
        ->expression('requests', 'requests + 1')
        ->expression('input_tokens', 'input_tokens + :in', [':in' => (int) $input_tokens])
        ->expression('output_tokens', 'output_tokens + :out', [':out' => (int) $output_tokens])
        ->execute();
    }
    catch (\Throwable) {
      // Usage accounting must never break inference.
    }
  }

  /**
   * Returns today's counters for a model.
   *
   * @return array{requests: int, input_tokens: int, output_tokens: int}
   *   Zeroes when the model has no usage today.
   */
  public function getToday(string $model_id): array {
    $row = $this->database->select('ai_provider_universal_usage', 'u')
      ->fields('u', ['requests', 'input_tokens', 'output_tokens'])
      ->condition('model_id', $model_id)
      ->condition('day', $this->today())
      ->execute()
      ->fetchAssoc();

    return [
      'requests' => (int) ($row['requests'] ?? 0),
      'input_tokens' => (int) ($row['input_tokens'] ?? 0),
      'output_tokens' => (int) ($row['output_tokens'] ?? 0),
    ];
  }

  /**
   * Returns today's counters aggregated over all models of a server.
   *
   * @return array{requests: int, input_tokens: int, output_tokens: int}
   *   Zeroes when the server has no usage today.
   */
  public function getTodayForServer(string $server_id): array {
    $model_ids = $this->entityTypeManager->getStorage('universal_model')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('server_id', $server_id)
      ->execute();

    $empty = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
    if (!$model_ids) {
      return $empty;
    }

    $query = $this->database->select('ai_provider_universal_usage', 'u')
      ->condition('model_id', $model_ids, 'IN')
      ->condition('day', $this->today());
    $query->addExpression('SUM(requests)', 'requests');
    $query->addExpression('SUM(input_tokens)', 'input_tokens');
    $query->addExpression('SUM(output_tokens)', 'output_tokens');
    $row = $query->execute()->fetchAssoc();

    return array_map('intval', ($row ?: []) + $empty);
  }

  /**
   * Today as YYYYMMDD in the site timezone.
   */
  protected function today(): int {
    return (int) date('Ymd', $this->time->getRequestTime());
  }

}
