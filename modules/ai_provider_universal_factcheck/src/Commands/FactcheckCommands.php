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
   * Reads JSON in MediaBiasFactCheck style (or similar raters).
   * See data/mbfc-ratings-sample.json in this module for the format.
   *
   * @command factcheck:sync-bias-ratings
   * @option file Path to JSON file. Defaults to the sample bundled with this module.
   * @option fetch Comma-separated domains to fetch live from the MBFC API (needs the mbfc_key setting). Replaces the file input.
   * @option update Update existing trusted sites (use --no-update to only create).
   * @aliases fcsyncbias
   * @usage drush factcheck:sync-bias-ratings
   *   Import from the bundled sample data.
   * @usage drush factcheck:sync-bias-ratings --file=/path/to/ratings.json --no-update
   *   Import a custom file, creating new sites only.
   * @usage drush factcheck:sync-bias-ratings --fetch=lanacion.com.ar,pagina12.com.ar
   *   Fetch fresh ratings for two domains from the MBFC API.
   */
  public function syncBiasRatings(array $options = ['file' => NULL, 'fetch' => NULL, 'update' => TRUE]): void {
    if ($options['fetch']) {
      $result = $this->biasRatingImporter->fetchFromApi(array_filter(array_map('trim', explode(',', $options['fetch']))));
      foreach ($result['errors'] as $error) {
        $this->output()->writeln("<comment>$error</comment>");
      }
      $data = $result['sites'];
      if (!$data) {
        $this->output()->writeln('<error>Nothing fetched from the MBFC API.</error>');
        return;
      }
    }
    else {
      $file = $options['file'] ?? dirname(__DIR__, 2) . '/data/mbfc-ratings-sample.json';

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
