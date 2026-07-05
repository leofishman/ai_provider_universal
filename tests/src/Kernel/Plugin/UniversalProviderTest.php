<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Plugin;

use Drupal\ai_provider_universal\Service\ModelCatalog;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_provider_universal\Kernel\Traits\HttpClientMockTrait;
use Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests the single (non-derived) universal provider plugin and model entities.
 *
 * Model config entities are used instead of State + derivatives.
 *
 * @group ai_provider_universal
 */
#[CoversClass(UniversalProvider::class)]
#[Group('ai_provider_universal')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
final class UniversalProviderTest extends KernelTestBase {

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
   * Tests that only the base 'universal' plugin exists (no derivatives).
   */
  public function testSingleNonDerivedProviderPlugin(): void {
    $plugin_manager = $this->container->get('ai.provider');
    $plugin_manager->clearCachedDefinitions();
    $definitions = $plugin_manager->getDefinitions();

    $this->assertArrayHasKey('universal', $definitions);
    $this->assertArrayNotHasKey('universal:local', $definitions);
    $this->assertArrayNotHasKey('universal:gpu', $definitions);
    $this->assertSame('Universal', (string) $definitions['universal']['label']);
  }

  /**
   * Tests that the ai_universal_model entity type stores models as config.
   *
   * Exercised after a server creation + simulated discovery.
   */
  public function testModelConfigEntities(): void {
    $etm = $this->container->get('entity_type.manager');
    $server_storage = $etm->getStorage('ai_universal_server');
    $model_storage = $etm->getStorage('ai_universal_model');

    $server = $server_storage->create([
      'id' => 'testserver',
      'label' => 'Test Server',
      'host_name' => 'http://127.0.0.1',
      'port' => '8080',
      'api_key' => '',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => '',
    ]);
    $server->save();

    // Simulate what discovery does: create model entities directly.
    $model_storage->create([
      'id' => 'testserver__llama3',
      'label' => 'llama3',
      'server_id' => 'testserver',
      'raw_model_id' => 'llama3',
      'detected_operation_types' => ['chat'],
      'operation_types' => [],
    ])->save();

    $models = $model_storage->loadByProperties(['server_id' => 'testserver']);
    $this->assertCount(1, $models);

    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $m */
    $m = reset($models);
    $this->assertSame('llama3', $m->getRawModelId());
    $this->assertSame(['chat'], $m->getEffectiveOperationTypes());

    // Apply override.
    $m->setOperationTypes(['embeddings']);
    $m->save();

    $reloaded = $model_storage->load('testserver__llama3');
    $this->assertSame(['embeddings'], $reloaded->getEffectiveOperationTypes());
  }

  /**
   * Tests that getConfiguredModels() is read-only (creates no config entities).
   *
   * Discovery is an explicit write path (::discoverModels()); the frequent read
   * calls from the AI subsystem must never persist config.
   */
  public function testGetConfiguredModelsIsReadOnly(): void {
    $etm = $this->container->get('entity_type.manager');
    $model_storage = $etm->getStorage('ai_universal_model');

    $etm->getStorage('ai_universal_server')->create([
      'id' => 'readonly',
      'label' => 'Read Only',
      'host_name' => 'http://127.0.0.1',
      'port' => '8080',
      'api_key' => '',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => '',
    ])->save();

    /** @var \Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider $provider */
    $provider = $this->container->get('ai.provider')
      ->createInstance('universal', ['server_id' => 'readonly']);

    // No models known yet, and no network is reachable in a kernel test.
    $result = $provider->getConfiguredModels();
    $this->assertSame([], $result);

    // The key assertion: the read call must not have created any model entity.
    $this->assertCount(0, $model_storage->loadByProperties(['server_id' => 'readonly']));
  }

  /**
   * Tests that model entity ids are sanitized to valid, unique config ids.
   */
  public function testModelEntityIdSanitization(): void {
    // ID building + machine-name normalisation now live in the ModelCatalog
    // service (extracted from the provider); both helpers are public there.
    /** @var \Drupal\ai_provider_universal\Service\ModelCatalog $catalog */
    $catalog = $this->container->get(ModelCatalog::class);

    // Slashes, dots and case are normalised; runs of separators collapse.
    $this->assertSame('qwen_qwen2_5_7b_instruct_q4_k_m', $catalog->getMachineName('Qwen/Qwen2.5-7B-Instruct-Q4_K_M'));
    $this->assertSame('llama3_8b', $catalog->getMachineName('llama3:8b'));

    // Resulting entity ids only contain [a-z0-9_] and the server separator.
    $id = $catalog->buildModelEntityId('gpu', $catalog->getMachineName('Qwen/Qwen2.5-7B-Instruct'));
    $this->assertSame('gpu__qwen_qwen2_5_7b_instruct', $id);
    $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $id);

    // Degenerate (all-special) raw id still yields a valid id.
    $degenerate = $catalog->buildModelEntityId('gpu', $catalog->getMachineName('///'));
    $this->assertSame('gpu__model', $degenerate);

    // Direct passing of unsanitized strings (uppercase, spaces, specials).
    $unsanitized = $catalog->buildModelEntityId('GPU-Server!', 'My Awesome Model / v2');
    $this->assertSame('gpu_server__my_awesome_model_v2', $unsanitized);

    // Degenerate server ID yields a fallback.
    $degenerate_server = $catalog->buildModelEntityId('!!!', '///');
    $this->assertSame('server__model', $degenerate_server);

    // Over-long names are capped and disambiguated deterministically.
    $long = str_repeat('a', 300);
    $capped = $catalog->buildModelEntityId('gpu', $long);
    $this->assertLessThanOrEqual(160, strlen($capped));
    $this->assertSame($capped, $catalog->buildModelEntityId('gpu', $long));

    // Collision check for different over-long raw model IDs.
    $long_diff = str_repeat('a', 299) . 'b';
    $capped_diff = $catalog->buildModelEntityId('gpu', $long_diff);
    $this->assertNotEquals($capped, $capped_diff);
    $this->assertLessThanOrEqual(160, strlen($capped_diff));
  }

  /**
   * Tests model discovery and filtering logic.
   *
   * Uses the HttpClientMockTrait to avoid duplicating MockHandler setup.
   */
  public function testModelDiscoveryAndFiltering(): void {
    $etm = $this->container->get('entity_type.manager');
    $server_storage = $etm->getStorage('ai_universal_server');
    $model_storage = $etm->getStorage('ai_universal_model');

    $server = $server_storage->create([
      'id' => 'discover_test',
      'label' => 'Discovery Test Server',
      'host_name' => 'http://127.0.0.1',
      'port' => '8080',
      'api_key' => '',
      'timeout' => 600,
      'operation_types' => [],
      'model_filter' => 'llama3*, !*old*',
    ]);
    $server->save();

    // Queue a single response for the /v1/models call.
    $modelsData = [
      [
        'id' => 'llama3-8b-instruct',
        'object' => 'model',
        'status' => ['args' => []],
      ],
      [
        'id' => 'mistral-7b',
        'object' => 'model',
        'status' => ['args' => []],
      ],
      [
        'id' => 'llama3-old',
        'object' => 'model',
        'status' => ['args' => []],
      ],
    ];

    $this->mockHttpClientResponses([
      $this->createModelsListResponse($modelsData),
    ]);

    /** @var \Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider $provider */
    $provider = $this->container->get('ai.provider')
      ->createInstance('universal', ['server_id' => 'discover_test']);

    // Run discovery — exercises loadClient() + OpenAI SDK models()->list().
    $discovered = $provider->discoverModels();

    // Only llama3-8b-instruct should survive the filter 'llama3*, !*old*'.
    $this->assertCount(1, $discovered);
    $this->assertArrayHasKey('discover_test__llama3_8b_instruct', $discovered);

    $models = $model_storage->loadMultiple();
    $this->assertCount(1, $models);
    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model_entity */
    $model_entity = reset($models);
    $this->assertSame('discover_test__llama3_8b_instruct', $model_entity->id());
    $this->assertSame('llama3-8b-instruct', $model_entity->getRawModelId());
  }

}
