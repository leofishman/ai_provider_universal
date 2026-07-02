<?php

declare(strict_types=1);

namespace Drupal\ai_provider_universal\Commands;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the universal AI provider module.
 */
class UniversalCommands extends DrushCommands {

  use StringTranslationTrait;

  /**
   * Constructs the commands object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiProviderPluginManager $aiProviderManager,
  ) {
    parent::__construct();
  }

  /**
   * Discovers and persists models for one or all configured servers.
   *
   * @param string|null $server_id
   *   Optional ID of the server to run discovery for. If omitted, runs for
   *   all servers.
   *
   * @command universal:discover-models
   * @aliases aiudm, universal-discover
   * @usage drush universal:discover-models
   *   Discover models for all configured servers.
   * @usage drush universal:discover-models my_server
   *   Discover models specifically for the server "my_server".
   */
  public function discoverModels(?string $server_id = NULL): void {
    $server_storage = $this->entityTypeManager->getStorage('universal_server');

    if ($server_id) {
      /** @var \Drupal\ai_provider_universal\Entity\UniversalServerInterface|null $server */
      $server = $server_storage->load($server_id);
      if (!$server) {
        $error = $this->t('Server "@id" not found.', ['@id' => $server_id]);
        $this->output()->writeln('<error>' . $error . '</error>');
        return;
      }
      $servers = [$server];
    }
    else {
      /** @var \Drupal\ai_provider_universal\Entity\UniversalServerInterface[] $servers */
      $servers = $server_storage->loadMultiple();
      if (empty($servers)) {
        $this->output()->writeln('<comment>' . $this->t('No configured servers found.') . '</comment>');
        return;
      }
    }

    foreach ($servers as $server) {
      // Build the message with placeholders so translators don't see markup.
      $message = $this->t('Running model discovery for server: <info>@label</info> (@id)...', [
        '@label' => $server->label(),
        '@id' => $server->id(),
      ]);
      $this->output()->writeln((string) $message);

      try {
        $provider = $this->aiProviderManager->createInstance('universal', ['server_id' => $server->id()]);

        // discoverModels() is public; the AI module's ProviderProxy forwards
        // public method calls, so we can call it directly on the object.
        $models = $provider->discoverModels();

        $success = $this->t('Success:');
        $summary = $this->t('Discovered and persisted @count model(s) for server "@id".', [
          '@count' => count($models),
          '@id' => $server->id(),
        ]);
        $this->output()->writeln('<info>' . $success . '</info> ' . $summary);

        foreach ($models as $eid => $raw) {
          $this->output()->writeln(sprintf('  - %s (raw ID: %s)', $eid, $raw));
        }
      }
      catch (\Throwable $e) {
        $error = $this->t('Failed to discover models for server "@id": @message', [
          '@id' => $server->id(),
          '@message' => $e->getMessage(),
        ]);
        $this->output()->writeln('<error>' . $error . '</error>');
      }
    }
  }

}
