<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_universal\Backend\ServerBackendManager;
use Drupal\ai_provider_universal\Service\ModelCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests capability detection in the openai_compatible backend and catalog.
 *
 * Covers the offline detection paths (server --args and model-name heuristics).
 * The HuggingFace pipeline_tag lookup is intentionally not exercised here: it
 * requires network and is only consulted when a --hf-repo arg is present.
 *
 * @group ai_provider_universal
 */
#[CoversClass(ModelCatalog::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
final class ModelCatalogTest extends KernelTestBase {

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
   * Tests offline operation-type detection from args and model-name heuristics.
   *
   * @dataProvider providerDetection
   */
  #[DataProvider('providerDetection')]
  public function testDetectOperationTypes(array $model, array $expected): void {
    /** @var \Drupal\ai_provider_universal\Backend\ServerBackendInterface $backend */
    $backend = $this->container->get(ServerBackendManager::class)
      ->createInstance('openai_compatible');
    $this->assertSame($expected, $backend->detectOperationTypes($model));
  }

  /**
   * Data provider: model entry => expected operation types.
   */
  public static function providerDetection(): array {
    return [
      // Explicit server flags win over name heuristics.
      'embeddings flag' => [
        ['id' => 'whatever-model', 'status' => ['args' => ['--embeddings']]],
        ['embeddings'],
      ],
      'reranking flag' => [
        ['id' => 'whatever-model', 'status' => ['args' => ['--reranking']]],
        ['rerank'],
      ],
      // Name-based heuristics (no informative args).
      'stable diffusion -> image' => [
        ['id' => 'stable-diffusion-xl', 'status' => ['args' => []]],
        ['text_to_image'],
      ],
      'flux -> image' => [
        ['id' => 'flux-dev', 'status' => ['args' => []]],
        ['text_to_image'],
      ],
      'whisper -> speech_to_text' => [
        ['id' => 'whisper-large-v3', 'status' => ['args' => []]],
        ['speech_to_text'],
      ],
      'rerank name' => [
        ['id' => 'bge-reranker-base', 'status' => ['args' => []]],
        ['rerank'],
      ],
      'shieldgemma -> moderation' => [
        ['id' => 'shieldgemma-2b', 'status' => ['args' => []]],
        ['moderation'],
      ],
      'llama guard -> moderation' => [
        ['id' => 'Llama-Guard-3-8B', 'status' => ['args' => []]],
        ['moderation'],
      ],
      'embedding name' => [
        ['id' => 'nomic-embed-text-v1.5', 'status' => ['args' => []]],
        ['embeddings'],
      ],
      'bge embedding' => [
        ['id' => 'bge-m3', 'status' => ['args' => []]],
        ['embeddings'],
      ],
      // Default fallback.
      'plain chat model' => [
        ['id' => 'qwen2.5-7b-instruct', 'status' => ['args' => []]],
        ['chat'],
      ],
      'missing status' => [
        ['id' => 'qwen2.5-7b-instruct'],
        ['chat'],
      ],
    ];
  }

  /**
   * Discovery errors must propagate (not be swallowed) so callers can log.
   */
  public function testDiscoveryErrorsPropagate(): void {
    /** @var \Drupal\ai_provider_universal\Service\ModelCatalog $catalog */
    $catalog = $this->container->get(ModelCatalog::class);

    $server = $this->container->get('entity_type.manager')
      ->getStorage('universal_server')
      ->create([
        'id' => 'unreachable',
        'label' => 'Unreachable',
        'host_name' => 'http://127.0.0.1',
        'port' => '1',
        'api_key' => '',
        'timeout' => 1,
        'operation_types' => [],
        'model_filter' => '',
      ]);
    $server->save();

    $this->expectException(\Throwable::class);
    $catalog->discoverModels($server);
  }

}
