<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Commands;

use Symfony\Component\Yaml\Yaml;
use Drupal\ai_provider_universal\Commands\UniversalCommands;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_provider_universal\Kernel\Traits\HttpClientMockTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests the Drush commands.
 *
 * @group ai_provider_universal
 */
#[CoversClass(UniversalCommands::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
final class UniversalCommandsTest extends KernelTestBase {

  use HttpClientMockTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'ai_provider_universal',
  ];

  /**
   * Tests that the Drush command service is declared and wirable.
   *
   * Drush.services.yml is loaded by Drush, not by the Drupal kernel, so the
   * service is not present in the kernel test container. Instead we assert the
   * declaration exists and its constructor args resolve from the container.
   */
  public function testDrushCommandServiceIsRegistered(): void {
    $yaml = Yaml::parseFile(
      \Drupal::service('extension.list.module')->getPath('ai_provider_universal') . '/drush.services.yml'
    );
    $this->assertArrayHasKey('ai_provider_universal.commands', $yaml['services']);
    $this->assertSame(
      UniversalCommands::class,
      $yaml['services']['ai_provider_universal.commands']['class']
    );

    // The declared constructor args must resolve from the container.
    $command = new UniversalCommands(
      $this->container->get('entity_type.manager'),
      $this->container->get('ai.provider')
    );
    $this->assertInstanceOf(UniversalCommands::class, $command);
  }

  /**
   * Tests the discover-models command when no servers are configured.
   */
  public function testDiscoverModelsNoServers(): void {
    $command = new UniversalCommands(
      $this->container->get('entity_type.manager'),
      $this->container->get('ai.provider')
    );

    $output = new BufferedOutput();
    $command->setOutput($output);

    $command->discoverModels();

    $this->assertStringContainsString('No configured llama.cpp servers found.', $output->fetch());
  }

  /**
   * Tests the discover-models command with a configured server (success path).
   *
   * Uses HttpClientMockTrait to provide a fake response so we can assert
   * that models are actually discovered and persisted.
   */
  public function testDiscoverModelsWithServer(): void {
    $etm = $this->container->get('entity_type.manager');
    $server_storage = $etm->getStorage('universal_server');

    $server = $server_storage->create([
      'id' => 'test_drush_server',
      'label' => 'Drush Server',
      'host_name' => 'http://127.0.0.1',
      'port' => '8080',
      'api_key' => '',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => '',
    ]);
    $server->save();

    // Use the shared trait helper to mock a successful discovery response.
    $this->mockHttpClientResponses([
      $this->createModelsListResponse([
        [
          'id' => 'llama3-8b',
          'object' => 'model',
          'status' => ['args' => []],
        ],
        [
          'id' => 'phi3',
          'object' => 'model',
          'status' => ['args' => []],
        ],
      ]),
    ]);

    $command = new UniversalCommands(
      $etm,
      $this->container->get('ai.provider')
    );

    $output = new BufferedOutput();
    $command->setOutput($output);

    $command->discoverModels('test_drush_server');

    $text = $output->fetch();
    $this->assertStringContainsString('Running model discovery for server: Drush Server (test_drush_server)', $text);
    $this->assertStringContainsString('Success: Discovered and persisted 2 model(s) for server "test_drush_server".', $text);
    $this->assertStringContainsString('test_drush_server__llama3_8b', $text);
  }

  /**
   * Tests discover-models command with a non-existent server ID (error path).
   */
  public function testDiscoverModelsNonExistentServer(): void {
    $command = new UniversalCommands(
      $this->container->get('entity_type.manager'),
      $this->container->get('ai.provider')
    );

    $output = new BufferedOutput();
    $command->setOutput($output);

    $command->discoverModels('invalid_server_id');

    $this->assertStringContainsString('Server "invalid_server_id" not found.', $output->fetch());
  }

}
