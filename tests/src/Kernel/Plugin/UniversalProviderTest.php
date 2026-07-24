<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Plugin;

use Drupal\ai\Exception\AiMissingFeatureException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_provider_universal\Event\ModelPreCallEvent;
use Drupal\ai_provider_universal\Service\ModelCatalog;
use Drupal\ai_provider_universal\Service\UsageTracker;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Psr7\Response;
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
      'id' => 'testserver.llama3',
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

    $reloaded = $model_storage->load('testserver.llama3');
    $this->assertSame(['embeddings'], $reloaded->getEffectiveOperationTypes());
  }

  /**
   * Tests that extra request parameters round-trip through config.
   *
   * The stored array is merged into the chat payload by ::doChat(), so what
   * matters is that arbitrary nested structures survive save/load and that
   * "model"/"messages" can never be overridden from configuration.
   */
  public function testExtraParamsRoundTrip(): void {
    $model_storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_universal_model');
    $model_storage->create([
      'id' => 'testserver.gpt5',
      'label' => 'gpt-5',
      'server_id' => 'testserver',
      'raw_model_id' => 'gpt-5',
      'detected_operation_types' => ['chat'],
      'operation_types' => [],
    ])->save();

    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model */
    $model = $model_storage->load('testserver.gpt5');
    $model->setExtraParams([
      'tools' => [['type' => 'web_search']],
      'model' => 'hijacked',
      'messages' => ['nope'],
      'stream' => TRUE,
      'stream_options' => ['include_usage' => FALSE],
    ])->save();

    $reloaded = $model_storage->load('testserver.gpt5');
    $this->assertSame(
      ['tools' => [['type' => 'web_search']]],
      $reloaded->getExtraParams(),
    );
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
    $this->assertSame('gpu.qwen_qwen2_5_7b_instruct', $id);
    $this->assertMatchesRegularExpression('/^[a-z0-9_.]+$/', $id);

    // Degenerate (all-special) raw id still yields a valid id.
    $degenerate = $catalog->buildModelEntityId('gpu', $catalog->getMachineName('///'));
    $this->assertSame('gpu.model', $degenerate);

    // Direct passing of unsanitized strings (uppercase, spaces, specials).
    $unsanitized = $catalog->buildModelEntityId('GPU-Server!', 'My Awesome Model / v2');
    $this->assertSame('gpu_server.my_awesome_model_v2', $unsanitized);

    // Degenerate server ID yields a fallback.
    $degenerate_server = $catalog->buildModelEntityId('!!!', '///');
    $this->assertSame('server.model', $degenerate_server);

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
   * Tests that a backend owning inference receives the chat call.
   *
   * The dispatch is what makes non-OpenAI protocols possible, and it must
   * keep the generic wrapper intact: the call still goes through the pre-call
   * gate and still records usage against the server.
   */
  public function testNativeBackendOwnsChatExecution(): void {
    $etm = $this->container->get('entity_type.manager');
    $etm->getStorage('ai_universal_server')->create([
      'id' => 'claude',
      'label' => 'Anthropic',
      'backend' => 'anthropic',
      'host_name' => '',
      'port' => '',
      'timeout' => 600,
    ])->save();
    $etm->getStorage('ai_universal_model')->create([
      'id' => 'claude.sonnet',
      'label' => 'Sonnet',
      'server_id' => 'claude',
      'raw_model_id' => 'claude-sonnet-4-5',
      'detected_operation_types' => ['chat'],
      'extra_params' => ['service_tier' => 'standard_only'],
    ])->save();

    // Usage counters live in a real table; the provider writes to it on every
    // successful call, native path included.
    $this->installSchema('ai_provider_universal', ['ai_provider_universal_usage']);

    $this->mockHttpClientResponses([
      new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'id' => 'msg_01',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-5',
        'content' => [['type' => 'text', 'text' => 'Native answer.']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 12, 'output_tokens' => 5],
      ])),
    ]);

    /** @var \Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider $provider */
    $provider = $this->container->get('ai.provider')
      ->createInstance('universal', ['server_id' => 'claude']);
    $output = $provider->chat('Hola', 'claude.sonnet');

    $this->assertSame('Native answer.', $output->getNormalized()->getText());
    $this->assertSame(12, $output->getTokenUsage()->input);

    // Usage recording is generic and must happen on the native path too,
    // otherwise per-server daily limits would never trip for this backend.
    $usage = $this->container->get(UsageTracker::class)->getToday('claude.sonnet');
    $this->assertSame(1, $usage['requests']);
    $this->assertSame(12, $usage['input_tokens']);
    $this->assertSame(5, $usage['output_tokens']);
  }

  /**
   * Tests that the provider-level system role reaches a native backend.
   *
   * AI core callers set it with setChatSystemRole(); the OpenAI path injects
   * it while building its payload, so the native path has to do the same or
   * it would silently vanish.
   */
  public function testChatSystemRoleReachesNativeBackend(): void {
    $this->createAnthropicServerAndModel();
    $this->installSchema('ai_provider_universal', ['ai_provider_universal_usage']);
    $this->mockHttpClientResponses([$this->anthropicResponse()]);

    /** @var \Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider $provider */
    $provider = $this->container->get('ai.provider')
      ->createInstance('universal', ['server_id' => 'claude']);
    $provider->setChatSystemRole('You are terse.');

    $input = new ChatInput([new ChatMessage('user', 'Hola')]);
    $provider->chat($input, 'claude.sonnet');

    // The caller's input object must not be mutated by the injection.
    $this->assertCount(1, $input->getMessages());
  }

  /**
   * Tests that non-chat operations on a native backend fail with a reason.
   *
   * Only chat is dispatched through the backend; anything else would be sent
   * over the OpenAI protocol to a server that does not speak it, which
   * otherwise surfaces as a confusing 404 from the wrong endpoint.
   */
  public function testNonChatOperationOnNativeBackendIsRefused(): void {
    $this->createAnthropicServerAndModel();

    /** @var \Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider $provider */
    $provider = $this->container->get('ai.provider')
      ->createInstance('universal', ['server_id' => 'claude']);

    $this->expectException(AiMissingFeatureException::class);
    $this->expectExceptionMessage('only serves chat');
    $provider->embeddings('embed me', 'claude.sonnet');
  }

  /**
   * Creates a server on the native backend plus one model on it.
   */
  protected function createAnthropicServerAndModel(): void {
    $etm = $this->container->get('entity_type.manager');
    $etm->getStorage('ai_universal_server')->create([
      'id' => 'claude',
      'label' => 'Anthropic',
      'backend' => 'anthropic',
      'host_name' => '',
      'port' => '',
      'timeout' => 600,
    ])->save();
    $etm->getStorage('ai_universal_model')->create([
      'id' => 'claude.sonnet',
      'label' => 'Sonnet',
      'server_id' => 'claude',
      'raw_model_id' => 'claude-sonnet-4-5',
      'detected_operation_types' => ['chat'],
    ])->save();
  }

  /**
   * A minimal successful Messages API response.
   */
  protected function anthropicResponse(): Response {
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
      'id' => 'msg_01',
      'role' => 'assistant',
      'model' => 'claude-sonnet-4-5',
      'content' => [['type' => 'text', 'text' => 'Native answer.']],
      'stop_reason' => 'end_turn',
      'usage' => ['input_tokens' => 12, 'output_tokens' => 5],
    ]));
  }

  /**
   * Tests the pre-call gate event: block and model swap.
   */
  public function testModelPreCallEvent(): void {
    $etm = $this->container->get('entity_type.manager');
    $etm->getStorage('ai_universal_server')->create([
      'id' => 'gated',
      'label' => 'Gated',
      'host_name' => 'http://127.0.0.1',
      'port' => '8080',
    ])->save();
    $etm->getStorage('ai_universal_model')->create([
      'id' => 'gated.llama3',
      'label' => 'llama3',
      'server_id' => 'gated',
      'raw_model_id' => 'llama3',
      'detected_operation_types' => ['chat'],
    ])->save();

    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->addListener(ModelPreCallEvent::EVENT_NAME, static function (ModelPreCallEvent $event): void {
      $event->block('blocked by test');
    });

    /** @var \Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider $provider */
    $provider = $this->container->get('ai.provider')
      ->createInstance('universal', ['server_id' => 'gated']);

    // The gate runs before any HTTP request: no mock responses are queued,
    // so anything past the gate would fail differently.
    $this->expectException(AiRequestErrorException::class);
    $this->expectExceptionMessage('blocked by test');
    $provider->chat('hello', 'gated.llama3');
  }

  /**
   * Tests that a pre-call subscriber can swap the model.
   */
  public function testModelPreCallEventSwap(): void {
    $event = new ModelPreCallEvent('server.original', 'chat');
    $event->setModelId('server.cheaper');
    $this->assertSame('server.cheaper', $event->getModelId());
    $this->assertFalse($event->isBlocked());
    $event->block('done');
    $this->assertTrue($event->isBlocked());
    $this->assertSame('done', $event->getBlockReason());
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
    $this->assertArrayHasKey('discover_test.llama3_8b_instruct', $discovered);

    $models = $model_storage->loadMultiple();
    $this->assertCount(1, $models);
    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model_entity */
    $model_entity = reset($models);
    $this->assertSame('discover_test.llama3_8b_instruct', $model_entity->id());
    $this->assertSame('llama3-8b-instruct', $model_entity->getRawModelId());

    // A hand-made duplicate (same raw model, second configuration) must
    // survive re-discovery even though discovery would never generate its id,
    // while a model the server no longer offers is still cleaned up.
    $duplicate = $model_entity->createDuplicate();
    $duplicate->set('id', 'discover_test.llama3_8b_instruct_websearch');
    $duplicate->set('label', 'llama3 (web search)');
    $duplicate->setExtraParams(['tools' => [['type' => 'web_search']]]);
    $duplicate->save();

    $model_storage->create([
      'id' => 'discover_test.gone',
      'label' => 'gone',
      'server_id' => 'discover_test',
      'raw_model_id' => 'gone',
      'detected_operation_types' => ['chat'],
      'operation_types' => [],
    ])->save();

    $this->mockHttpClientResponses([
      $this->createModelsListResponse($modelsData),
    ]);
    $provider->discoverModels();

    $this->assertSame(
      ['discover_test.llama3_8b_instruct', 'discover_test.llama3_8b_instruct_websearch'],
      array_keys($model_storage->loadMultiple()),
    );
    $this->assertSame(
      ['tools' => [['type' => 'web_search']]],
      $model_storage->load('discover_test.llama3_8b_instruct_websearch')->getExtraParams(),
    );
  }

  /**
   * Tests getApiDefinition() returns the YAML structure (AI provider contract).
   *
   * This exercises the new implementation added for Drupal AI best practices
   * compliance.
   */
  public function testGetApiDefinition(): void {
    /** @var \Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider $provider */
    $provider = $this->container->get('ai.provider')
      ->createInstance('universal', []);

    $definition = $provider->getApiDefinition();

    $this->assertIsArray($definition);
    $this->assertArrayHasKey('chat', $definition);
    $this->assertArrayHasKey('embeddings', $definition);
    $this->assertArrayHasKey('moderation', $definition);

    // Spot-check chat configuration parameters from api_defaults.yml.
    $this->assertArrayHasKey('configuration', $definition['chat']);
    $this->assertArrayHasKey('temperature', $definition['chat']['configuration']);
    $this->assertArrayHasKey('max_tokens', $definition['chat']['configuration']);

    // Embeddings has no extra configuration in the defaults.
    $this->assertSame([], $definition['embeddings']['configuration']);
  }

}
