<?php

namespace Drupal\ai_provider_universal_router\Service;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai_provider_universal\Entity\AiUniversalModelInterface;
use Drupal\ai_provider_universal_router\Entity\AiUniversalRouteInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Picks the cheapest capable model for a route, per request.
 *
 * Pure decision logic, no HTTP: given a route and the request input it
 * classifies the prompt (simple/complex), filters candidate models by
 * operation type, context window and quality tier, and returns the cheapest
 * survivor. Every decision is written to the ai_universal_router_log table
 * for the savings dashboard.
 */
class RouteDecider {

  /**
   * Quality tier assumed for models the user has not rated.
   */
  protected const DEFAULT_TIER = 3;

  /**
   * Assumed output length (tokens) for cost estimation.
   */
  protected const ASSUMED_OUTPUT_TOKENS = 512;

  /**
   * The last resolve() decision (route id + complexity), for call tagging.
   *
   * @var array{route_id: string, complexity: string}|null
   */
  protected ?array $lastDecision = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected LoggerInterface $logger,
    protected UsageLimitEnforcer $limitEnforcer,
    protected ComplexityClassifier $classifier,
  ) {}

  /**
   * Resolves a route to a concrete ai_universal_model entity id.
   *
   * @param string $routeId
   *   The ai_universal_route entity id.
   * @param mixed $input
   *   The operation input (ChatInput, string, array, ...); used for
   *   complexity classification and context-fit checks.
   * @param string $operationType
   *   The operation type being executed.
   *
   * @return string
   *   The chosen ai_universal_model entity id.
   *
   * @throws \RuntimeException
   *   When the route does not exist or no candidate is available.
   */
  public function resolve(string $routeId, mixed $input, string $operationType = 'chat'): string {
    $route = $this->entityTypeManager->getStorage('ai_universal_route')->load($routeId);
    if (!$route instanceof AiUniversalRouteInterface) {
      throw new \RuntimeException(sprintf('Smart route "%s" does not exist.', $routeId));
    }

    $text = $this->extractText($input);
    $estTokens = $this->estimateTokens($text);
    $complexity = $this->classify($text, $estTokens);
    $requiredTier = $complexity === 'complex' ? $route->getComplexTier() : $route->getSimpleTier();

    $candidates = $this->candidateModels($route, $operationType);
    if (!$candidates) {
      throw new \RuntimeException(sprintf('Smart route "%s" has no candidate models for operation "%s". Run model discovery or edit the route.', $routeId, $operationType));
    }

    $eligible = array_filter($candidates, function (AiUniversalModelInterface $model) use ($estTokens, $requiredTier) {
      $ctx = $model->getContextLength();
      if ($ctx !== NULL && $estTokens + self::ASSUMED_OUTPUT_TOKENS > $ctx) {
        return FALSE;
      }
      return ($model->getQualityTier() ?? self::DEFAULT_TIER) >= $requiredTier;
    });

    // No candidate satisfies the tier: degrade gracefully to the best
    // available (highest tier, then cheapest) instead of failing the request.
    if (!$eligible) {
      $this->logger->warning('Route @route: no candidate reaches tier @tier for a @complexity prompt; falling back to best available.', [
        '@route' => $routeId,
        '@tier' => $requiredTier,
        '@complexity' => $complexity,
      ]);
      $eligible = $candidates;
      usort($eligible, fn ($a, $b) =>
        [($b->getQualityTier() ?? self::DEFAULT_TIER), -$this->costOf($a, $estTokens)]
        <=> [($a->getQualityTier() ?? self::DEFAULT_TIER), -$this->costOf($b, $estTokens)]);
    }
    else {
      // Cheapest first; on equal cost prefer the higher tier.
      usort($eligible, fn ($a, $b) =>
        [$this->costOf($a, $estTokens), ($b->getQualityTier() ?? self::DEFAULT_TIER)]
        <=> [$this->costOf($b, $estTokens), ($a->getQualityTier() ?? self::DEFAULT_TIER)]);
    }

    $chosen = reset($eligible);
    $worst = max(array_map(fn ($m) => $this->costOf($m, $estTokens), $candidates));

    $this->log($route, $operationType, $complexity, $estTokens, $chosen, count($candidates), $this->costOf($chosen, $estTokens), $worst);
    $this->lastDecision = [
      'route_id' => (string) $route->id(),
      'complexity' => $complexity,
    ];

    return $chosen->id();
  }

  /**
   * The last resolve() decision, for tagging the resulting AI call.
   *
   * @return array{route_id: string, complexity: string}|null
   *   Route id and complexity class, or NULL before any resolution.
   */
  public function getLastDecision(): ?array {
    return $this->lastDecision;
  }

  /**
   * Resolves the strongest candidate for a route, for escalation.
   *
   * Used by the fact-check cascade: when a routed answer fails
   * verification, retry with the highest-tier candidate (cheapest among
   * equals), excluding models already tried.
   *
   * @return string|null
   *   The model entity id, or NULL when there is nothing to escalate to.
   */
  public function resolveBest(string $routeId, string $operationType = 'chat', array $exclude = []): ?string {
    $route = $this->entityTypeManager->getStorage('ai_universal_route')->load($routeId);
    if (!$route instanceof AiUniversalRouteInterface) {
      return NULL;
    }

    $candidates = array_filter(
      $this->candidateModels($route, $operationType),
      static fn (AiUniversalModelInterface $m) => !in_array($m->id(), $exclude, TRUE),
    );
    if (!$candidates) {
      return NULL;
    }

    usort($candidates, fn ($a, $b) =>
      [($b->getQualityTier() ?? self::DEFAULT_TIER), $this->costOf($a, 0)]
      <=> [($a->getQualityTier() ?? self::DEFAULT_TIER), $this->costOf($b, 0)]);

    $chosen = reset($candidates);
    $this->log($route, $operationType, 'escalated', 0, $chosen, count($candidates), $this->costOf($chosen, 0), $this->costOf($chosen, 0));
    return $chosen->id();
  }

  /**
   * Loads candidate models: the route's list, or all capable models.
   *
   * @return \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface[]
   *   Candidate model entities supporting the operation type.
   */
  protected function candidateModels(AiUniversalRouteInterface $route, string $operationType): array {
    $storage = $this->entityTypeManager->getStorage('ai_universal_model');
    $ids = $route->getCandidates();
    $models = $ids ? $storage->loadMultiple($ids) : $storage->loadMultiple();

    // Models whose server exhausted a daily usage limit drop out of the
    // candidate pool, so routing fails over to another provider.
    $servers = $this->entityTypeManager->getStorage('ai_universal_server')
      ->loadMultiple(array_unique(array_map(
        static fn (AiUniversalModelInterface $m) => $m->getServerId(),
        $models,
      )));

    $required = $route->getRequiredFeatures();

    return array_filter($models, fn (AiUniversalModelInterface $m) =>
      in_array($operationType, $m->getEffectiveOperationTypes(), TRUE)
      && !array_diff($required, $m->getSupportedFeatures())
      && (!isset($servers[$m->getServerId()])
        || !$this->limitEnforcer->isServerOverLimit($servers[$m->getServerId()])));
  }

  /**
   * Estimated request cost in USD (input + assumed output tokens).
   *
   * Unknown costs count as 0, which naturally prefers local/self-hosted
   * models; set explicit costs on remote models so they compare correctly.
   */
  public function costOf(AiUniversalModelInterface $model, int $estTokens): float {
    $in = ($model->getCostInput() ?? 0.0) * $estTokens;
    $out = ($model->getCostOutput() ?? 0.0) * self::ASSUMED_OUTPUT_TOKENS;
    return ($in + $out) / 1000000;
  }

  /**
   * Classifies a prompt as 'simple' or 'complex'.
   */
  public function classify(string $text, int $estTokens): string {
    return $this->classifier->classify($text, $estTokens);
  }

  /**
   * Rough token estimate (~4 chars per token).
   */
  public function estimateTokens(string $text): int {
    return (int) ceil(mb_strlen($text) / 4);
  }

  /**
   * Extracts plain text from the various operation input shapes.
   */
  protected function extractText(mixed $input): string {
    if (is_string($input)) {
      return $input;
    }
    if ($input instanceof ChatInput) {
      $parts = [];
      foreach ($input->getMessages() as $message) {
        $parts[] = $message->getText();
      }
      return implode("\n", $parts);
    }
    if (is_array($input)) {
      return implode("\n", array_map(
        static fn ($item) => is_scalar($item) ? (string) $item : json_encode($item),
        $input,
      ));
    }
    return '';
  }

  /**
   * Persists one decision row for the dashboard.
   */
  protected function log(AiUniversalRouteInterface $route, string $operationType, string $complexity, int $estTokens, AiUniversalModelInterface $chosen, int $candidateCount, float $estCost, float $worstCost): void {
    try {
      $this->database->insert('ai_universal_router_log')
        ->fields([
          'timestamp' => time(),
          'route_id' => (string) $route->id(),
          'operation_type' => $operationType,
          'complexity' => $complexity,
          'est_tokens' => $estTokens,
          'chosen_model' => (string) $chosen->id(),
          'candidates' => $candidateCount,
          'est_cost' => $estCost,
          'est_cost_worst' => $worstCost,
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      // Logging must never break inference.
      $this->logger->error('Failed to record routing decision: @message', ['@message' => $e->getMessage()]);
    }
  }

}
