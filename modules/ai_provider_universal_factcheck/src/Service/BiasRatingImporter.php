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
  ];

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
   *   - credibility, notes, source (optional).
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
        'field_reputation' => $this->mapToReputation($site['factual'] ?? 'mixed', $site['bias'] ?? 'center'),
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

    $sites = [];
    $errors = [];
    foreach ($domains as $domain) {
      $domain = strtolower(trim($domain));
      try {
        $response = $this->httpClient->request('GET', 'https://media-bias-fact-check-ratings-api2.p.rapidapi.com/ratings', [
          'headers' => [
            'X-RapidAPI-Key' => $apiKey,
            'X-RapidAPI-Host' => 'media-bias-fact-check-ratings-api2.p.rapidapi.com',
          ],
          'query' => ['domain' => $domain],
          'timeout' => 10,
        ]);
        $data = json_decode((string) $response->getBody(), TRUE);
        // Some endpoints wrap the record in a list or a "data" envelope.
        if (isset($data[0]) && is_array($data[0])) {
          $data = $data[0];
        }
        elseif (isset($data['data']) && is_array($data['data'])) {
          $data = is_array($data['data'][0] ?? NULL) ? $data['data'][0] : $data['data'];
        }
        if (!is_array($data) || !$data) {
          $errors[] = "$domain: empty or unrecognized response.";
          continue;
        }
        $sites[] = [
          'domain' => $domain,
          'name' => $data['name'] ?? $data['source'] ?? $domain,
          'bias' => $data['bias'] ?? $data['bias_rating'] ?? '',
          'factual' => $data['factual'] ?? $data['factual_reporting'] ?? '',
          'credibility' => $data['credibility'] ?? '',
          'notes' => $data['notes'] ?? '',
          'source' => 'MediaBiasFactCheck API',
        ];
      }
      catch (\Throwable $e) {
        $errors[] = "$domain: " . $e->getMessage();
      }
    }
    return ['sites' => $sites, 'errors' => $errors];
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
    $header = sprintf('%s — Bias: %s | Factual: %s', $source, $site['bias'] ?? '?', $site['factual'] ?? '?');
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
