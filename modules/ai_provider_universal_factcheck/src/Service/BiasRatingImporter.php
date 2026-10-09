<?php

declare(strict_types=1);

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Imports bias and factual ratings into trusted_site nodes.
 *
 * Consumes JSON in MediaBiasFactCheck style; other raters (AllSides,
 * Ad Fontes, etc.) can be imported via the same format.
 */
class BiasRatingImporter {

  /**
   * Maps rater bias labels to field_bias allowed values.
   */
  protected const BIAS_MAP = [
    'left' => 'left',
    'left-center' => 'lean_left',
    'lean left' => 'lean_left',
    'lean_left' => 'lean_left',
    'center' => 'center',
    'least biased' => 'center',
    'right-center' => 'lean_right',
    'lean right' => 'lean_right',
    'lean_right' => 'lean_right',
    'right' => 'right',
    'extreme left' => 'left',
    'extreme right' => 'right',
  ];

  /**
   * Lin et al. (2023) domain quality ratings, pinned to a commit.
   */
  public const LIN2023_URL = 'https://raw.githubusercontent.com/hauselin/domain-quality-ratings/5671d57e545e48225ef2cbf559a6ab7f8f77a9de/data/domain_pc1.csv';

  /**
   * SHA-256 of the file at LIN2023_URL.
   */
  public const LIN2023_SHA256 = '18221d8c4ce30e25ceab4ce3d1c9a7babaf9e935bd42ed2c89e3c52fc19d5e1a';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected ClientInterface $httpClient,
  ) {}

  /**
   * Import an array of sites.
   *
   * @param array $sites
   *   Each item should have:
   *   - domain (required)
   *   - name (optional)
   *   - bias (e.g. 'Right', 'Left-Center', 'Center')
   *   - factual (e.g. 'High', 'Mixed', 'Low')
   *   - credibility, notes, source (optional)
   *   - reputation (optional, -10..+10): used as is instead of deriving it
   *     from bias and factual.
   * @param bool $update_existing
   *   Whether to overwrite reputation/assessments on existing sites.
   *
   * @return array{created: int, updated: int, skipped: int}
   *   Import statistics.
   */
  public function importFromArray(array $sites, bool $update_existing = TRUE): array {
    $storage = $this->entityTypeManager->getStorage('node');

    $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

    foreach ($sites as $site) {
      if (empty($site['domain'])) {
        $stats['skipped']++;
        continue;
      }

      $domain = strtolower(trim($site['domain']));
      $name = $site['name'] ?? $domain;

      $existing = $storage->loadByProperties([
        'type' => 'trusted_site',
        'field_domain' => $domain,
      ]);
      /** @var \Drupal\node\NodeInterface|null $node */
      $node = $existing ? reset($existing) : NULL;

      if ($node && !$update_existing) {
        $stats['skipped']++;
        continue;
      }

      $values = [
        'type' => 'trusted_site',
        'title' => $name,
        'field_domain' => $domain,
        'field_reputation' => isset($site['reputation'])
          ? max(-10, min(10, (int) $site['reputation']))
          : $this->mapToReputation($site['factual'] ?? 'mixed', $site['bias'] ?? 'center'),
        'status' => 1,
      ];

      $assessments = $this->buildAssessments($site);
      if ($assessments) {
        $values['field_assessments'] = array_map(
          static fn ($a) => ['value' => $a],
          $assessments
        );
      }

      $bias = self::BIAS_MAP[strtolower(trim($site['bias'] ?? ''))] ?? NULL;
      if ($bias) {
        $values['field_bias'] = $bias;
      }

      if ($node) {
        foreach ($values as $field => $value) {
          $node->set($field, $value);
        }
        $node->save();
        $stats['updated']++;
      }
      else {
        $storage->create($values)->save();
        $stats['created']++;
      }
    }

    return $stats;
  }

  /**
   * Fetch ratings for the given domains from the MBFC RapidAPI.
   *
   * Requires the mbfc_key setting to point at a Key entity holding a
   * RapidAPI key subscribed to the "Media Bias Fact Check Ratings" API
   * (https://rapidapi.com/mbfcnews/api/media-bias-fact-check-ratings-api2).
   *
   * The /fetch-data endpoint returns the full dataset (~15k sources, columns
   * "Source", "Bias", "Factual Reporting", "Source URL", "Credibility",
   * "Political Bias"), so this makes exactly one HTTP call regardless of
   * how many domains are requested — the free tier allows 3 calls/month.
   *
   * @param string[] $domains
   *   Domains to look up.
   *
   * @return array{sites: array, errors: string[]}
   *   Sites in the same format as the JSON import, plus per-domain errors.
   */
  public function fetchFromApi(array $domains): array {
    $keyId = (string) $this->configFactory->get('ai_provider_universal_factcheck.settings')->get('mbfc_key');
    $apiKey = $keyId ? (string) ($this->keyRepository->getKey($keyId)?->getKeyValue() ?? '') : '';
    if (!$apiKey) {
      return ['sites' => [], 'errors' => ['No MBFC API key configured (mbfc_key setting).']];
    }

    try {
      $response = $this->httpClient->request('GET', 'https://media-bias-fact-check-ratings-api2.p.rapidapi.com/fetch-data', [
        'headers' => [
          'X-RapidAPI-Key' => $apiKey,
          'X-RapidAPI-Host' => 'media-bias-fact-check-ratings-api2.p.rapidapi.com',
        ],
        'timeout' => 60,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
    }
    catch (\Throwable $e) {
      return ['sites' => [], 'errors' => ['MBFC API request failed: ' . $e->getMessage()]];
    }

    if (isset($data['data']) && is_array($data['data'])) {
      $data = $data['data'];
    }
    if (!is_array($data) || !$data) {
      return ['sites' => [], 'errors' => ['Empty or unrecognized MBFC API response.']];
    }

    $index = [];
    foreach ($data as $row) {
      if (is_array($row) && !empty($row['Source URL'])) {
        $index[$this->normalizeDomain((string) $row['Source URL'])] = $row;
      }
    }

    $sites = [];
    $errors = [];
    foreach ($domains as $domain) {
      $domain = $this->normalizeDomain($domain);
      $row = $index[$domain] ?? NULL;
      if (!$row) {
        $errors[] = "$domain: not found in MBFC ratings.";
        continue;
      }
      // "Bias" can be an editorial label like "Questionable" that carries no
      // left/right signal; fall back to "Political Bias" for placement.
      $bias = (string) ($row['Bias'] ?? '');
      if (!isset(self::BIAS_MAP[strtolower(trim($bias))]) && !empty($row['Political Bias'])) {
        $bias = (string) $row['Political Bias'];
      }
      $sites[] = [
        'domain' => $domain,
        'name' => $row['Source'] ?? $domain,
        'bias' => $bias,
        'factual' => $row['Factual Reporting'] ?? '',
        'credibility' => $row['Credibility'] ?? '',
        'notes' => '',
        'source' => 'MediaBiasFactCheck API',
      ];
    }
    return ['sites' => $sites, 'errors' => $errors];
  }

  /**
   * Fetch quality ratings for the given domains from Lin et al. (2023).
   *
   * Downloads domain_pc1.csv (~11.5k domains, ~400 KB) at a pinned commit
   * and refuses it if the checksum changed, so imports are reproducible and
   * a modified file is noticed. The dataset has one quality score (pc1,
   * 0..1) and no bias, so field_bias is left as it is.
   *
   * @param string[] $domains
   *   Domains to look up.
   *
   * @return array{sites: array, errors: string[]}
   *   Sites in the same format as the JSON import, plus per-domain errors.
   */
  public function fetchFromLin2023(array $domains): array {
    try {
      $body = (string) $this->httpClient->request('GET', static::LIN2023_URL, ['timeout' => 60])->getBody();
    }
    catch (\Throwable $e) {
      return ['sites' => [], 'errors' => ['Lin et al. 2023 download failed: ' . $e->getMessage()]];
    }
    if (!hash_equals(static::LIN2023_SHA256, hash('sha256', $body))) {
      return ['sites' => [], 'errors' => ['Lin et al. 2023 file checksum mismatch; refusing to import it.']];
    }

    $index = [];
    foreach (preg_split('/\r?\n/', trim($body)) as $line) {
      [$domain, $pc1] = str_getcsv($line, ',', '"', '') + [1 => ''];
      if (is_numeric($pc1)) {
        $index[$this->normalizeDomain($domain)] = (float) $pc1;
      }
    }

    $sites = [];
    $errors = [];
    foreach ($domains as $domain) {
      $domain = $this->normalizeDomain($domain);
      if (!isset($index[$domain])) {
        $errors[] = "$domain: not found in Lin et al. 2023.";
        continue;
      }
      $pc1 = $index[$domain];
      $sites[] = [
        'domain' => $domain,
        'reputation' => $this->pc1ToReputation($pc1),
        'source' => 'Lin et al. 2023, PNAS Nexus, https://doi.org/10.1093/pnasnexus/pgad286',
        'notes' => sprintf('Domain quality (pc1, 0 to 1): %.2f.', $pc1),
      ];
    }
    return ['sites' => $sites, 'errors' => $errors];
  }

  /**
   * Maps a Lin et al. pc1 score (0..1) onto the -10..+10 reputation scale.
   *
   * Linear. Against the hand-curated seeds it lands within 2 points for
   * apnews.com, reuters.com, nature.com and wikipedia.org, and ranks
   * infowars.com (-9), breitbart.com (-4) and foxnews.com (+1) below them.
   * It undershoots some (who.int +6, science.org +2): curated sites win,
   * import them with --no-update.
   */
  public function pc1ToReputation(float $pc1): int {
    return max(-10, min(10, (int) round($pc1 * 20 - 10)));
  }

  /**
   * Reduces a URL or hostname to its bare domain (no scheme, www or path).
   */
  protected function normalizeDomain(string $url): string {
    $url = preg_replace('~^(https?://)?(www\.)?~', '', strtolower(trim($url)));
    return explode('/', $url)[0];
  }

  /**
   * Maps MBFC-style factual + bias labels to the -10..+10 reputation scale.
   */
  protected function mapToReputation(string $factual, string $bias): int {
    $factual = strtolower(trim($factual));
    $bias = strtolower(trim($bias));

    $base = match ($factual) {
      'very high' => 9,
      'high' => 8,
      'mostly factual', 'mostly high' => 5,
      'mixed' => 1,
      'low' => -6,
      'very low' => -8,
      default => 0,
    };

    $adjustment = match ($bias) {
      'center', 'least biased' => 1,
      'left-center', 'right-center', 'lean left', 'lean right', 'lean_left', 'lean_right' => 0,
      'left', 'right' => -2,
      default => -3,
    };

    return max(-10, min(10, $base + $adjustment));
  }

  /**
   * Builds human-readable assessments from the import data.
   */
  protected function buildAssessments(array $site): array {
    $assessments = [];

    $source = $site['source'] ?? 'Media Bias / Fact Check';
    $header = isset($site['bias']) || isset($site['factual'])
      ? sprintf('%s — Bias: %s | Factual: %s', $source, $site['bias'] ?? '?', $site['factual'] ?? '?')
      : $source;
    if (!empty($site['credibility'])) {
      $header .= ' | Credibility: ' . $site['credibility'];
    }
    $assessments[] = $header;

    if (!empty($site['notes'])) {
      $assessments[] = is_array($site['notes']) ? implode(' ', $site['notes']) : $site['notes'];
    }

    return $assessments;
  }

}
