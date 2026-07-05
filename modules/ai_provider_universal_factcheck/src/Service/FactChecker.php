<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Cache\CacheBackendInterface;
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
 *    an answer with no factual claims scores 1.0). Claims echoed by
 *    distrusted (negative-reputation) sites are marked tainted and each
 *    subtracts an extra half point: misinformation sites asserting a claim
 *    is evidence against it.
 *
 * Checker models whose id contains "minicheck" (Bespoke-MiniCheck) are
 * driven through their fine-tuned interface instead of the generic prompt:
 * "Document: ...\nClaim: ..." answered with Yes/No. They cannot extract
 * claims and cannot judge without evidence, so they need a separate
 * extractor model and an evidence index. MiniCheck also cannot batch, so it
 * always runs the per-claim path regardless of profile.
 *
 * The verification profile setting trades cost/latency against depth:
 * - fast: one batched verdict call for all claims, 2 evidence passages per
 *   claim, no distrusted-echo check, no discrepancy analysis, verdicts
 *   cached 6h.
 * - balanced (default): batched verdicts, 3 passages, one answer-level
 *   distrusted check, discrepancy analysis on unsettled claims, cached 1h.
 * - thorough: per-claim verdict calls, 5 passages, per-claim distrusted
 *   checks, discrepancy analysis, no caching.
 */
class FactChecker {

  /**
   * What each verification profile enables.
   *
   * Distrusted: 'off' | 'answer' (one check for all claims) | 'claim'
   * (one Tavily search + one checker call per claim).
   */
  protected const PROFILES = [
    'fast' => [
      'batch_verify' => TRUE,
      'evidence_limit' => 2,
      'distrusted' => 'off',
      'analysis' => FALSE,
      'cache_ttl' => 21600,
    ],
    'balanced' => [
      'batch_verify' => TRUE,
      'evidence_limit' => 3,
      'distrusted' => 'answer',
      'analysis' => TRUE,
      'cache_ttl' => 3600,
    ],
    'thorough' => [
      'batch_verify' => FALSE,
      'evidence_limit' => 5,
      'distrusted' => 'claim',
      'analysis' => TRUE,
      'cache_ttl' => 0,
    ],
  ];

  protected const EXTRACT_PROMPT = <<<PROMPT
Extract up to %d atomic factual claims from the text below.

Rules:
- A factual claim is a single, verifiable statement about the world (can be true or false).
- Extract claims in the **original language** of the text (do not translate).
- Ignore opinions, questions, hedges ("probably", "I think"), instructions, and meta text.
- Output **ONLY** a valid JSON array of strings. No explanations, no markdown, no code fences, no extra text.

TEXT:
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

  protected const BATCH_VERIFY_PROMPT = <<<PROMPT
You are a strict fact checker. Judge every numbered CLAIM below
independently. For each claim pick exactly one verdict:
- SUPPORTED: the claim is correct%s
- CONTRADICTED: the claim conflicts with the evidence or is factually wrong
- UNSUPPORTED: cannot be established either way

Respond ONLY with a JSON array of objects like
[{"id": 1, "verdict": "SUPPORTED"}], one object per claim, no prose.

%s
PROMPT;

  protected const TAINT_PROMPT = <<<PROMPT
The EVIDENCE below comes from low-reputation (distrusted) sites. For each
numbered CLAIM, decide whether the evidence asserts the claim. Respond ONLY
with a JSON array of the numbers of the asserted claims, e.g. [1,3].
Respond [] when none are asserted.

%s

EVIDENCE:
%s
PROMPT;

  protected const ANALYZE_PROMPT = <<<PROMPT
The evidence below did not settle the CLAIM. Each source is annotated with
its curated reputation (-10 to 10, higher is more trustworthy) and, when
available, notes from media watchdogs about the outlet itself. For each
source, summarize its position on the claim in one sentence. If the sources
disagree with each other, state which side is better supported and why,
weighing the strength of the arguments, the reputations and the watchdog
notes. Maximum 4 short lines. If the evidence simply does not address the
claim, respond only with the word NONE.

CLAIM: %s

EVIDENCE:
%s
PROMPT;

  public function __construct(
    protected AiProviderPluginManager $providerManager,
    protected ConfigFactoryInterface $configFactory,
    protected EvidenceRetriever $evidenceRetriever,
    protected TrustedSiteRepository $trustedSites,
    protected CacheBackendInterface $cache,
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
   * @return array{score: float, claims: array<int, array{claim: string, verdict: string, tainted: bool, analysis: string, coverage: array}>}
   *   Support score in [0, 1] and the per-claim verdicts. 'tainted' means
   *   the claim is also asserted by distrusted sites, which lowers the
   *   score. 'analysis' is a short source-by-source discrepancy analysis,
   *   produced only when multiple evidence sources failed to settle the
   *   claim; empty otherwise. 'coverage' is the shape of the web evidence
   *   (see coverage()); empty for local-index or model-only verification.
   *   What runs (batching, distrusted checks, analysis, caching) is governed
   *   by the 'profile' setting.
   */
  public function verify(string $question, string $answer): array {
    return $this->verifyClaims($this->extractClaims($answer, $question));
  }

  /**
   * Verifies already-extracted claims. Same contract as verify().
   *
   * Split out so callers (e.g. the content scan batch) can run extraction
   * and verification as separate steps.
   *
   * @param string[] $claims
   *   The claims to verify.
   *
   * @return array{score: float, claims: array}
   *   See verify().
   */
  public function verifyClaims(array $claims): array {
    $profile = $this->profileSettings();
    if (!$claims) {
      return ['score' => 1.0, 'claims' => []];
    }

    $miniCheck = str_contains(strtolower((string) $this->settings()->get('checker_model')), 'minicheck');
    $ttl = (int) $profile['cache_ttl'];

    // Per-claim records, cache first: unchanged claims cost nothing on a
    // re-scan.
    $records = [];
    $pending = [];
    foreach ($claims as $i => $claim) {
      if ($ttl && ($hit = $this->cache->get($this->claimCid($claim)))) {
        $records[$i] = $hit->data;
      }
      else {
        $pending[$i] = $claim;
      }
    }

    if ($pending) {
      // Evidence once per claim, shared by verdict and analysis.
      $evidence = array_map(
        fn (string $claim): array => $this->evidenceRetriever->retrieve($claim, $profile['evidence_limit']),
        $pending,
      );

      // One batched verdict call when the profile (and model) allow it;
      // per-claim calls otherwise or for claims the batch failed to cover.
      $verdicts = (!$miniCheck && $profile['batch_verify'])
        ? $this->batchVerify($pending, $evidence)
        : [];
      foreach ($pending as $i => $claim) {
        $verdicts[$i] ??= $this->verifyClaim($claim, $evidence[$i]);
      }

      $taintedKeys = match (TRUE) {
        $profile['distrusted'] === 'off' => [],
        $profile['distrusted'] === 'answer' && !$miniCheck => $this->taintedForAnswer($pending),
        default => array_keys(array_filter($pending, fn (string $c): bool => $this->echoedByDistrusted($c))),
      };

      foreach ($pending as $i => $claim) {
        // ponytail: analyze only non-SUPPORTED claims with >=2 sources — the
        // case where trusted sources may be disagreeing. Run it on every
        // multi-source claim if missed disagreements behind SUPPORTED
        // verdicts ever matter (doubles checker calls).
        $analysis = ($profile['analysis'] && $verdicts[$i] !== 'SUPPORTED' && count($evidence[$i]) >= 2)
          ? $this->analyzeDiscrepancy($claim, $evidence[$i])
          : '';
        $records[$i] = [
          'claim' => $claim,
          'verdict' => $verdicts[$i],
          'tainted' => in_array($i, $taintedKeys, TRUE),
          'analysis' => $analysis,
          'coverage' => self::coverage($evidence[$i], $this->trustedSites->profileMap()),
        ];
        if ($ttl) {
          $this->cache->set($this->claimCid($claim), $records[$i], time() + $ttl);
        }
      }
    }

    ksort($records);
    $supported = count(array_filter($records, static fn (array $r): bool => $r['verdict'] === 'SUPPORTED'));
    $tainted = count(array_filter($records, static fn (array $r): bool => $r['tainted']));

    // ponytail: fixed half-point penalty per tainted claim; make it
    // configurable if curators ever need per-site weights.
    return [
      'score' => max(0.0, ($supported - 0.5 * $tainted) / count($claims)),
      'claims' => array_values($records),
    ];
  }

  /**
   * Extracts atomic factual claims from an answer.
   *
   * @param string $answer
   *   The text to extract from.
   * @param string $context
   *   Optional original question or title for disambiguation.
   *
   * @return string[]
   *   The claims; empty when extraction fails (treated as "nothing to
   *   verify" — the answer passes rather than hard-failing inference).
   */
  public function extractClaims(string $answer, string $context = ''): array {
    $max = (int) ($this->settings()->get('max_claims') ?: 5);
    $extractor = (string) ($this->settings()->get('extractor_model') ?: $this->settings()->get('checker_model'));

    $promptText = $answer;
    if ($context) {
      $promptText = "Context / question: {$context}\n\nText to analyze:\n{$answer}";
    }
    $raw = $this->ask(sprintf(self::EXTRACT_PROMPT, $max, $promptText), $extractor);

    $claims = [];

    // 1. Best: a strict JSON array anywhere in the response (handles
    // ```json fences too). Models sometimes return [{"claim": "..."}]
    // instead of plain strings.
    if (preg_match('/\[.*\]/s', $raw, $match)) {
      $decoded = json_decode($match[0], TRUE);
      if (is_array($decoded)) {
        $claims = array_map(
          static fn ($c) => is_array($c) ? ($c['claim'] ?? '') : $c,
          $decoded,
        );
        // Drop non-string junk now so the fallbacks below still get a shot
        // when the decoded array held nothing usable.
        $claims = array_filter($claims, static fn ($c) => is_string($c) && trim($c) !== '');
      }
    }

    // 2. Bullet / numbered list fallback.
    if (empty($claims)) {
      $lines = explode("\n", $raw);
      foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/^(?:[-*]|\d+\.)\s+(.+)$/', $line, $lineMatch)) {
          $claims[] = trim($lineMatch[1]);
        }
      }
    }

    // 3. Last resort heuristic: split into sentences and keep those that look
    // like declarative statements (helps when the model ignores the JSON rule).
    if (empty($claims)) {
      $sentences = preg_split('/(?<=[.!?…])\s+/u', $answer);
      foreach ($sentences as $s) {
        $s = trim($s);
        if (mb_strlen($s) > 15 && !preg_match('/^(who|what|when|where|why|how|es|son|está|será|¿|\?)/i', $s)) {
          // Very rough filter: skip obvious questions / very short.
          $claims[] = $s;
        }
      }
    }

    $claims = array_values(array_filter(array_map(
      static fn ($c) => is_string($c) ? trim($c, " \t\n\r\0\x0B\"'") : '',
      $claims,
    )));

    if (empty($claims) && $raw) {
      $this->logger->info('Factcheck extractor returned no claims. Raw response (first 300): @raw', [
        '@raw' => mb_substr($raw, 0, 300),
      ]);
    }

    return array_slice($claims, 0, $max);
  }

  /**
   * Verdict for a single claim, judged on the given evidence passages.
   *
   * @return string
   *   SUPPORTED / UNSUPPORTED / CONTRADICTED.
   */
  protected function verifyClaim(string $claim, array $passages): string {
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
   * Judges all claims in one checker call.
   *
   * @param array<int, string> $claims
   *   Claims keyed by their original index.
   * @param array<int, string[]> $evidence
   *   Evidence passages per claim, same keys.
   *
   * @return array<int, string>
   *   Verdicts keyed like $claims; missing entries (model skipped a claim
   *   or returned unparseable JSON) fall back to per-claim verification in
   *   the caller.
   */
  protected function batchVerify(array $claims, array $evidence): array {
    $keys = array_keys($claims);
    $hasEvidence = (bool) array_filter($evidence);

    $blocks = [];
    foreach ($keys as $n => $i) {
      $block = 'CLAIM ' . ($n + 1) . ': ' . $claims[$i];
      if ($evidence[$i]) {
        $block .= "\nEVIDENCE " . ($n + 1) . ":\n- " . implode("\n- ", array_map(
          static fn (string $p): string => mb_substr($p, 0, 500),
          $evidence[$i],
        ));
      }
      $blocks[] = $block;
    }

    $prompt = sprintf(
      self::BATCH_VERIFY_PROMPT,
      $hasEvidence ? ' according to its evidence' : ' to the best of your knowledge',
      implode("\n\n", $blocks),
    );
    $raw = $this->ask($prompt, (string) $this->settings()->get('checker_model'));

    if (!preg_match('/\[.*\]/s', $raw, $match) || !is_array($items = json_decode($match[0], TRUE))) {
      return [];
    }
    $verdicts = [];
    foreach ($items as $item) {
      $n = (int) ($item['id'] ?? 0) - 1;
      $verdict = strtoupper(trim((string) ($item['verdict'] ?? '')));
      if (isset($keys[$n]) && in_array($verdict, ['SUPPORTED', 'CONTRADICTED', 'UNSUPPORTED'], TRUE)) {
        $verdicts[$keys[$n]] = $verdict;
      }
    }
    return $verdicts;
  }

  /**
   * Claims asserted by distrusted sites: one search + one call per answer.
   *
   * Cheaper sibling of echoedByDistrusted(): a single Tavily search over the
   * concatenated claims and a single checker call naming the tainted ones.
   *
   * @param array<int, string> $claims
   *   Claims keyed by their original index.
   *
   * @return int[]
   *   Keys of the tainted claims.
   */
  protected function taintedForAnswer(array $claims): array {
    // Tavily queries are short; the joined claims act as a topic query and
    // the checker does the precise claim-by-claim matching.
    $query = mb_substr(implode('. ', $claims), 0, 380);
    $passages = $this->evidenceRetriever->retrieveDistrusted($query);
    if (!$passages) {
      return [];
    }

    $keys = array_keys($claims);
    $list = [];
    foreach ($keys as $n => $i) {
      $list[] = 'CLAIM ' . ($n + 1) . ': ' . $claims[$i];
    }
    $evidenceBlock = '- ' . implode("\n- ", array_map(
      static fn (string $p): string => mb_substr($p, 0, 500),
      $passages,
    ));
    $raw = $this->ask(
      sprintf(self::TAINT_PROMPT, implode("\n", $list), $evidenceBlock),
      (string) $this->settings()->get('checker_model'),
    );

    if (!preg_match('/\[.*?\]/s', $raw, $match) || !is_array($numbers = json_decode($match[0], TRUE))) {
      return [];
    }
    return array_values(array_filter(array_map(
      static fn ($n) => $keys[(int) $n - 1] ?? NULL,
      $numbers,
    ), static fn ($k) => $k !== NULL));
  }

  /**
   * Source-by-source analysis when multiple sources fail to settle a claim.
   *
   * Annotates each passage with its domain's curated reputation and asks the
   * checker to summarize each source's argument and pick the better-supported
   * side. Answers the "two well-scored sites disagree" case.
   *
   * @return string
   *   Short analysis text; empty when the model finds no real disagreement
   *   or the checker cannot do free-form analysis (MiniCheck).
   */
  protected function analyzeDiscrepancy(string $claim, array $passages): string {
    $model = (string) ($this->settings()->get('extractor_model') ?: $this->settings()->get('checker_model'));
    if (str_contains(strtolower($model), 'minicheck')) {
      return '';
    }

    $lines = array_map(function (string $p): string {
      $profile = ['reputation' => 0, 'assessments' => []];
      if (preg_match('/^\[(\S+)\]/', $p, $match)) {
        $host = strtolower((string) parse_url($match[1], PHP_URL_HOST));
        if ($host !== '') {
          $profile = $this->trustedSites->profile($host);
        }
      }
      // Watchdog assessments are curated with their rater named, so the
      // model sees "who says this about the outlet", not a bare verdict.
      $notes = $profile['assessments']
        ? '; watchdog notes: ' . mb_substr(implode(' | ', $profile['assessments']), 0, 300)
        : '';
      return sprintf('- (reputation %+d%s) %s', $profile['reputation'], $notes, mb_substr($p, 0, 500));
    }, $passages);

    $raw = trim($this->ask(sprintf(self::ANALYZE_PROMPT, $claim, implode("\n", $lines)), $model));
    return strtoupper($raw) === 'NONE' ? '' : $raw;
  }

  /**
   * Ground-News-style summary of the web evidence behind a claim.
   *
   * Describes the SHAPE of the coverage rather than adjudicating it: how
   * many distinct web sources, how many are independent (unique owners —
   * wire copy republished by sibling outlets is not independent
   * confirmation), the spread of known editorial biases, and a blindspot
   * flag when every leaning source falls on one side of the spectrum.
   *
   * Pure text logic (no state) so it is unit-testable in isolation.
   *
   * @param string[] $passages
   *   Source-prefixed evidence passages.
   * @param array<string, array{reputation: int, bias: string, owner: string, assessments: string[]}> $profiles
   *   Curated domain profiles (TrustedSiteRepository::profileMap()).
   *
   * @return array{sources: int, independent: int, biases: array<string, int>, blindspot: string}|array{}
   *   The summary; empty when no passage carries a source URL (local-index
   *   and model-only evidence has no web coverage to describe).
   */
  public static function coverage(array $passages, array $profiles): array {
    $domains = [];
    foreach ($passages as $passage) {
      if (preg_match('/^\[(\S+)\]/', $passage, $match)) {
        $host = strtolower((string) parse_url($match[1], PHP_URL_HOST));
        if ($host !== '') {
          $domains[$host] = TRUE;
        }
      }
    }
    if (!$domains) {
      return [];
    }

    $owners = [];
    $biases = [];
    $left = $right = 0;
    foreach (array_keys($domains) as $domain) {
      $profile = $profiles[$domain] ?? [];
      // Unknown owner: the domain stands for itself.
      $owners[strtolower((string) ($profile['owner'] ?? '')) ?: $domain] = TRUE;
      if ($bias = (string) ($profile['bias'] ?? '')) {
        $biases[$bias] = ($biases[$bias] ?? 0) + 1;
        $left += (int) in_array($bias, ['left', 'lean_left'], TRUE);
        $right += (int) in_array($bias, ['right', 'lean_right'], TRUE);
      }
    }

    return [
      'sources' => count($domains),
      'independent' => count($owners),
      'biases' => $biases,
      // ponytail: blindspot = leaning sources on exactly one side; upgrade
      // to proportion thresholds if center-heavy mixes need nuance.
      'blindspot' => match (TRUE) {
        $left > 0 && $right === 0 => 'left',
        $right > 0 && $left === 0 => 'right',
        default => '',
      },
    ];
  }

  /**
   * Whether distrusted (negative-reputation) sites assert the claim.
   *
   * Uses the same grounded-support question as verification, but against
   * evidence fetched exclusively from negative-reputation domains. Costs
   * one Tavily search and one checker call per claim, and only runs when
   * distrusted domains and a Tavily key are configured.
   */
  protected function echoedByDistrusted(string $claim): bool {
    $passages = $this->evidenceRetriever->retrieveDistrusted($claim);
    if (!$passages) {
      return FALSE;
    }
    $checker = (string) $this->settings()->get('checker_model');

    if (str_contains(strtolower($checker), 'minicheck')) {
      $document = implode("\n", array_map(static fn ($p) => mb_substr($p, 0, 1000), $passages));
      $raw = $this->ask("Document: {$document}\nClaim: {$claim}", $checker);
      return str_starts_with(strtolower(trim($raw)), 'yes');
    }

    $evidenceBlock = "\n\nEVIDENCE:\n- " . implode("\n- ", array_map(
      static fn ($p) => mb_substr($p, 0, 500),
      $passages,
    ));
    $raw = strtoupper($this->ask(sprintf(self::VERIFY_PROMPT, $evidenceBlock, ' according to the evidence', $claim), $checker));
    return (bool) preg_match('/\bSUPPORTED\b/', $raw);
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
   * The active verification profile's knobs.
   */
  protected function profileSettings(): array {
    $name = (string) ($this->settings()->get('profile') ?: 'balanced');
    return self::PROFILES[$name] ?? self::PROFILES['balanced'];
  }

  /**
   * Cache id for a claim's verdict record.
   *
   * Keyed on the full settings so any config change (models, profile,
   * evidence index) naturally invalidates cached verdicts.
   */
  protected function claimCid(string $claim): string {
    return 'ai_provider_universal_factcheck:claim:' . sha1(serialize($this->settings()->getRawData()) . $claim);
  }

  /**
   * Module settings.
   */
  protected function settings() {
    return $this->configFactory->get('ai_provider_universal_factcheck.settings');
  }

}
