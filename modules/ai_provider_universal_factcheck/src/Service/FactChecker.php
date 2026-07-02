<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies an answer claim by claim and returns a support score.
 *
 * Pipeline (Fabric-style prompts, embedded — no external pattern files):
 * 1. Extract up to N atomic factual claims from the answer, using the
 *    extractor model (falls back to the checker model when unset).
 * 2. For each claim, retrieve evidence from the configured index (optional)
 *    and ask the checker model for a verdict:
 *    SUPPORTED / UNSUPPORTED / CONTRADICTED.
 * 3. score = supported / total (claims with no verdict count as unsupported;
 *    an answer with no factual claims scores 1.0).
 *
 * Checker models whose id contains "minicheck" (Bespoke-MiniCheck) are
 * driven through their fine-tuned interface instead of the generic prompt:
 * "Document: ...\nClaim: ..." answered with Yes/No. They cannot extract
 * claims and cannot judge without evidence, so they need a separate
 * extractor model and an evidence index.
 */
class FactChecker {

  protected const EXTRACT_PROMPT = <<<PROMPT
Extract the atomic factual claims from the following answer. A factual claim
is a single, verifiable statement about the world. Ignore opinions, hedges
and instructions. Respond ONLY with a JSON array of strings, at most %d
items, no prose.

ANSWER:
%s
PROMPT;

  protected const VERIFY_PROMPT = <<<PROMPT
You are a strict fact checker. Judge the CLAIM below.%s
Respond with exactly one word:
- SUPPORTED: the claim is correct%s
- CONTRADICTED: the claim conflicts with the evidence or is factually wrong
- UNSUPPORTED: cannot be established either way

CLAIM: %s
PROMPT;

  public function __construct(
    protected AiProviderPluginManager $providerManager,
    protected ConfigFactoryInterface $configFactory,
    protected EvidenceRetriever $evidenceRetriever,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether a checker model is configured and fact checking can run.
   */
  public function isConfigured(): bool {
    return (bool) $this->settings()->get('checker_model');
  }

  /**
   * Verifies an answer.
   *
   * @param string $question
   *   The original user question (context for the checker).
   * @param string $answer
   *   The generated answer to verify.
   *
   * @return array{score: float, claims: array<int, array{claim: string, verdict: string}>}
   *   Support score in [0, 1] and the per-claim verdicts.
   */
  public function verify(string $question, string $answer): array {
    $claims = $this->extractClaims($answer);
    if (!$claims) {
      return ['score' => 1.0, 'claims' => []];
    }

    $results = [];
    $supported = 0;
    foreach ($claims as $claim) {
      $verdict = $this->verifyClaim($claim);
      $results[] = ['claim' => $claim, 'verdict' => $verdict];
      if ($verdict === 'SUPPORTED') {
        $supported++;
      }
    }

    return [
      'score' => $supported / count($claims),
      'claims' => $results,
    ];
  }

  /**
   * Extracts atomic factual claims from an answer.
   *
   * @return string[]
   *   The claims; empty when extraction fails (treated as "nothing to
   *   verify" — the answer passes rather than hard-failing inference).
   */
  public function extractClaims(string $answer): array {
    $max = (int) ($this->settings()->get('max_claims') ?: 5);
    $extractor = (string) ($this->settings()->get('extractor_model') ?: $this->settings()->get('checker_model'));
    $raw = $this->ask(sprintf(self::EXTRACT_PROMPT, $max, $answer), $extractor);

    // Models often wrap JSON in fences or prose; grab the first array.
    if (!preg_match('/\[.*\]/s', $raw, $match)) {
      return [];
    }
    $claims = json_decode($match[0], TRUE);
    if (!is_array($claims)) {
      return [];
    }
    $claims = array_values(array_filter(array_map(
      static fn ($c) => is_string($c) ? trim($c) : '',
      $claims,
    )));
    return array_slice($claims, 0, $max);
  }

  /**
   * Verdict for a single claim: SUPPORTED / UNSUPPORTED / CONTRADICTED.
   */
  protected function verifyClaim(string $claim): string {
    $passages = $this->evidenceRetriever->retrieve($claim);
    $checker = (string) $this->settings()->get('checker_model');

    // Specialized grounded-checking models (Bespoke-MiniCheck) only know
    // one task: does this document support this claim? Yes/No.
    if (str_contains(strtolower($checker), 'minicheck')) {
      if (!$passages) {
        $this->logger->warning('MiniCheck checker needs an evidence index; claim treated as unsupported: @claim', ['@claim' => $claim]);
        return 'UNSUPPORTED';
      }
      $document = implode("\n", array_map(static fn ($p) => mb_substr($p, 0, 1000), $passages));
      $raw = $this->ask("Document: {$document}\nClaim: {$claim}", $checker);
      // MiniCheck's "No" means "not grounded", not "false": UNSUPPORTED.
      return str_starts_with(strtolower(trim($raw)), 'yes') ? 'SUPPORTED' : 'UNSUPPORTED';
    }

    if ($passages) {
      $evidenceBlock = "\n\nEVIDENCE:\n- " . implode("\n- ", array_map(
        static fn ($p) => mb_substr($p, 0, 500),
        $passages,
      ));
      $supportedSuffix = ' according to the evidence';
    }
    else {
      $evidenceBlock = '';
      $supportedSuffix = ' to the best of your knowledge';
    }

    $raw = strtoupper($this->ask(sprintf(self::VERIFY_PROMPT, $evidenceBlock, $supportedSuffix, $claim), $checker));

    foreach (['SUPPORTED', 'CONTRADICTED', 'UNSUPPORTED'] as $verdict) {
      // Order matters: check SUPPORTED before UNSUPPORTED would match inside
      // it, so use word boundaries.
      if (preg_match('/\b' . $verdict . '\b/', $raw)) {
        return $verdict;
      }
    }
    return 'UNSUPPORTED';
  }

  /**
   * Sends a single-message chat to the given model.
   */
  protected function ask(string $prompt, string $model): string {
    if (!$model) {
      return '';
    }

    try {
      $provider = $this->providerManager->createInstance('universal');
      $output = $provider->chat(new ChatInput([new ChatMessage('user', $prompt)]), $model, ['ai_provider_universal_factcheck']);
      return trim($output->getNormalized()->getText());
    }
    catch (\Throwable $e) {
      $this->logger->error('Fact check call failed: @message', ['@message' => $e->getMessage()]);
      return '';
    }
  }

  /**
   * Module settings.
   */
  protected function settings() {
    return $this->configFactory->get('ai_provider_universal_factcheck.settings');
  }

}
