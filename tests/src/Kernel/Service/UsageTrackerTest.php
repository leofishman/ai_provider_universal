<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_universal\Service\UsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests daily usage tracking for models and servers.
 *
 * @group ai_provider_universal
 */
#[CoversClass(UsageTracker::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
final class UsageTrackerTest extends KernelTestBase {

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
   * The usage tracker service under test.
   */
  protected UsageTracker $usageTracker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ai_provider_universal', ['ai_provider_universal_usage']);
    $this->usageTracker = $this->container->get(UsageTracker::class);
  }

  /**
   * Tests recording and retrieving usage for a single model.
   */
  public function testRecordAndGetToday(): void {
    // Initially, there should be no usage recorded.
    $usage = $this->usageTracker->getToday('test_model');
    $this->assertSame(0, $usage['requests']);
    $this->assertSame(0, $usage['input_tokens']);
    $this->assertSame(0, $usage['output_tokens']);

    // Record some usage.
    $this->usageTracker->record('test_model', 100, 50);

    $usage = $this->usageTracker->getToday('test_model');
    $this->assertSame(1, $usage['requests']);
    $this->assertSame(100, $usage['input_tokens']);
    $this->assertSame(50, $usage['output_tokens']);

    // Record more usage and check accumulation.
    $this->usageTracker->record('test_model', 200, 150);

    $usage = $this->usageTracker->getToday('test_model');
    $this->assertSame(2, $usage['requests']);
    $this->assertSame(300, $usage['input_tokens']);
    $this->assertSame(200, $usage['output_tokens']);
  }

  /**
   * Tests retrieving aggregated usage for all models of a server.
   */
  public function testRecordAndGetTodayForServer(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $model_storage = $entity_type_manager->getStorage('ai_universal_model');

    // Create models belonging to different servers.
    $model_storage->create([
      'id' => 'server_a__model_1',
      'label' => 'Server A Model 1',
      'server_id' => 'server_a',
      'raw_model_id' => 'model-1',
    ])->save();

    $model_storage->create([
      'id' => 'server_a__model_2',
      'label' => 'Server A Model 2',
      'server_id' => 'server_a',
      'raw_model_id' => 'model-2',
    ])->save();

    $model_storage->create([
      'id' => 'server_b__model_3',
      'label' => 'Server B Model 3',
      'server_id' => 'server_b',
      'raw_model_id' => 'model-3',
    ])->save();

    // No usage initially.
    $usage = $this->usageTracker->getTodayForServer('server_a');
    $this->assertSame(0, $usage['requests']);
    $this->assertSame(0, $usage['input_tokens']);
    $this->assertSame(0, $usage['output_tokens']);

    // Record usage for models on server_a.
    $this->usageTracker->record('server_a__model_1', 10, 20);
    $this->usageTracker->record('server_a__model_2', 30, 40);

    // Record usage for model on server_b.
    $this->usageTracker->record('server_b__model_3', 100, 200);

    // Verify server_a aggregated usage.
    $usage_a = $this->usageTracker->getTodayForServer('server_a');
    $this->assertSame(2, $usage_a['requests']);
    $this->assertSame(40, $usage_a['input_tokens']);
    $this->assertSame(60, $usage_a['output_tokens']);

    // Verify server_b usage.
    $usage_b = $this->usageTracker->getTodayForServer('server_b');
    $this->assertSame(1, $usage_b['requests']);
    $this->assertSame(100, $usage_b['input_tokens']);
    $this->assertSame(200, $usage_b['output_tokens']);
  }

}
