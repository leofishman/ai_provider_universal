<?php

declare(strict_types=1);

namespace Drupal\ai_provider_universal_factcheck\Commands;

use Drupal\ai_provider_universal_factcheck\Service\BiasRatingImporter;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the fact-check submodule.
 */
class FactcheckCommands extends DrushCommands {

  public function __construct(
    protected BiasRatingImporter $biasRatingImporter,
  ) {
    parent::__construct();
  }

  /**
   * Import bias and factual ratings into Trusted Sites.
   *
   * Reads JSON in MediaBiasFactCheck style (or similar raters); see
   * data/bias-ratings-example.json in this module for the format (invented
   * rows). No ratings ship with the module: raters' data is not GPL, so it
   * comes from a file you are licensed to use, or is downloaded via --fetch:
   * from the MBFC API (default) or, with --source=lin2023, from the open
   * Lin et al. (2023) quality ratings (no bias; pinned to a commit and a SHA-256).
   * Only the listed domains are imported: they become Tavily include and
   * exclude domains, so a bulk import would crowd out curated ones.
   *
   * @command factcheck:sync-bias-ratings
   * @option file Path to a JSON file of ratings.
   * @option fetch Comma-separated domains to fetch live. Replaces the file input.
   * @option source Where --fetch reads from: mbfc (needs the mbfc_key setting) or lin2023.
   * @option update Update existing trusted sites (use --no-update to only create).
   * @aliases fcsyncbias
   * @usage drush factcheck:sync-bias-ratings --file=/path/to/ratings.json
   *   Import a file of ratings.
   * @usage drush factcheck:sync-bias-ratings --file=/path/to/ratings.json --no-update
   *   Import a file, creating new sites only.
   * @usage drush factcheck:sync-bias-ratings --fetch=lanacion.com.ar,pagina12.com.ar
   *   Fetch fresh ratings for two domains from the MBFC API.
   * @usage drush factcheck:sync-bias-ratings --source=lin2023 --fetch=apnews.com,breitbart.com --no-update
   *   Rate two new domains from Lin et al. (2023), leaving curated ones alone.
   */
  public function syncBiasRatings(
    array $options = [
      'file' => NULL,
      'fetch' => NULL,
      'source' => 'mbfc',
      'update' => TRUE,
    ],
  ): void {
    if ($options['fetch']) {
      $domains = array_filter(array_map('trim', explode(',', $options['fetch'])));
      $result = match ($options['source']) {
        'mbfc' => $this->biasRatingImporter->fetchFromApi($domains),
        'lin2023' => $this->biasRatingImporter->fetchFromLin2023($domains),
        default => [
          'sites' => [],
          'errors' => ["Unknown --source={$options['source']}: use mbfc or lin2023."],
        ],
      };
      foreach ($result['errors'] as $error) {
        $this->output()->writeln("<comment>$error</comment>");
      }
      $data = $result['sites'];
      if (!$data) {
        $this->output()->writeln('<error>Nothing fetched.</error>');
        return;
      }
    }
    else {
      $file = $options['file'];
      if (!$file) {
        $this->output()->writeln('<error>Pass --file=ratings.json (format: data/bias-ratings-example.json in the factcheck submodule) or --fetch=domain,…</error>');
        return;
      }
      if (!file_exists($file)) {
        $this->output()->writeln("<error>File not found: $file</error>");
        return;
      }

      $data = json_decode((string) file_get_contents($file), TRUE);
      if (!is_array($data)) {
        $this->output()->writeln('<error>Invalid JSON: expected an array of site ratings.</error>');
        return;
      }
    }

    $stats = $this->biasRatingImporter->importFromArray($data, (bool) $options['update']);

    $this->output()->writeln(sprintf(
      'Bias ratings sync complete. Created: %d, Updated: %d, Skipped: %d',
      $stats['created'],
      $stats['updated'],
      $stats['skipped'],
    ));
  }

}
