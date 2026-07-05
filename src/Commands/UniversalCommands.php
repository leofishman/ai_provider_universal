<?php

declare(strict_types=1);

namespace Drupal\ai_provider_universal\Commands;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
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
   * @command aip:discover-models
   * @aliases aipdm, aip-discover
   * @usage drush aip:discover-models
   *   Discover models for all configured servers.
   * @usage drush aip:discover-models my_server
   *   Discover models specifically for the server "my_server".
   */
  public function discoverModels(?string $server_id = NULL): void {
    $server_storage = $this->entityTypeManager->getStorage('ai_universal_server');

    if ($server_id) {
      /** @var \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface|null $server */
      $server = $server_storage->load($server_id);
      if (!$server) {
        $error = $this->t('Server "@id" not found.', ['@id' => $server_id]);
        $this->output()->writeln('<error>' . $error . '</error>');
        return;
      }
      $servers = [$server];
    }
    else {
      /** @var \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface[] $servers */
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

  /**
   * Sends one chat prompt to a model or smart route.
   *
   * @param string $prompt
   *   The prompt text.
   * @param string $model_id
   *   An ai_universal_model entity id, or "route__<id>" for a smart route.
   * @param array $options
   *   Drush options.
   *
   * @command aip:chat
   * @option system Optional system prompt.
   * @usage drush aip:chat "What is 2+2?" my_server__llama3
   *   Ask a specific model.
   * @usage drush aip:chat "What is 2+2?" route__my_route
   *   Ask through a smart route (the router picks the model).
   */
  public function chat(string $prompt, string $model_id, array $options = ['system' => '']): void {
    $provider = $this->aiProviderManager->createInstance('universal');
    if ($options['system']) {
      $provider->setChatSystemRole($options['system']);
    }
    $input = new ChatInput([new ChatMessage('user', $prompt)]);

    $start = microtime(TRUE);
    $response = $provider->chat($input, $model_id, ['aip_chat']);
    $elapsed = (int) round((microtime(TRUE) - $start) * 1000);

    $this->output()->writeln($response->getNormalized()->getText());
    $usage = $response->getTokenUsage();
    $this->output()->writeln(sprintf(
      '<comment>[%s] %d ms, tokens in/out: %s/%s</comment>',
      $model_id, $elapsed, $usage->input ?? '?', $usage->output ?? '?',
    ));
  }

}
