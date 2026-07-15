<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_router\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_universal_router\Service\RouteDecider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests cost-aware candidate selection in RouteDecider.
 *
 * @group ai_provider_universal
 */
#[CoversClass(RouteDecider::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class RouteDeciderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'ai_provider_universal',
    'ai_provider_universal_router',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ai_provider_universal_router', ['ai_universal_router_log']);

    $model_storage = $this->container->get('entity_type.manager')->getStorage('ai_universal_model');
    // Local: free, mid quality, small context.
    $model_storage->create([
      'id' => 'local.qwen',
      'label' => 'Local / qwen',
      'server_id' => 'local',
      'raw_model_id' => 'qwen2.5-7b',
      'detected_operation_types' => ['chat'],
      'cost_input' => 0.0,
      'cost_output' => 0.0,
      'quality_tier' => 3,
      'context_length' => 8192,
    ])->save();
    // Remote: cheap but strong.
    $model_storage->create([
      'id' => 'fw.llama70b',
      'label' => 'Fireworks / llama 70b',
      'server_id' => 'fw',
      'raw_model_id' => 'llama-v3p1-70b',
      'detected_operation_types' => ['chat'],
      'cost_input' => 0.9,
      'cost_output' => 0.9,
      'quality_tier' => 4,
      'context_length' => 131072,
      'supported_features' => ['json_mode', 'tools'],
    ])->save();
    // Remote: expensive frontier.
    $model_storage->create([
      'id' => 'fw.deepseek',
      'label' => 'Fireworks / deepseek r1',
      'server_id' => 'fw',
      'raw_model_id' => 'deepseek-r1',
      'detected_operation_types' => ['chat'],
      'cost_input' => 3.0,
      'cost_output' => 8.0,
      'quality_tier' => 5,
      'context_length' => 163840,
    ])->save();

    $this->container->get('entity_type.manager')->getStorage('ai_universal_route')->create([
      'id' => 'default_chat',
      'label' => 'Default chat',
      'operation_type' => 'chat',
      'candidates' => [],
      'simple_tier' => 2,
      'complex_tier' => 4,
    ])->save();
  }

  /**
   * Required catalog features exclude models that don't report them.
   */
  public function testRequiredFeaturesFilterCandidates(): void {
    $this->container->get('entity_type.manager')->getStorage('ai_universal_route')->create([
      'id' => 'tools_chat',
      'label' => 'Tools chat',
      'operation_type' => 'chat',
      'candidates' => [],
      'simple_tier' => 2,
      'complex_tier' => 4,
      'required_features' => ['tools'],
    ])->save();

    $decider = $this->container->get(RouteDecider::class);
    // Only fw.llama70b reports the tools feature: the free local model is
    // skipped even for a simple prompt.
    $this->assertSame('fw.llama70b', $decider->resolve('tools_chat', 'Hola, ¿como estas?'));
  }

  /**
   * Simple prompts go to the free local model.
   */
  public function testSimplePromptPicksCheapest(): void {
    $decider = $this->container->get(RouteDecider::class);
    $this->assertSame('local.qwen', $decider->resolve('default_chat', 'Hola, ¿como estas?'));
  }

  /**
   * Complex prompts require the higher tier: cheapest tier>=4 wins.
   */
  public function testComplexPromptEscalates(): void {
    $decider = $this->container->get(RouteDecider::class);
    $chosen = $decider->resolve('default_chat', "Refactor this code step by step:\n```php\necho 'hi';\n```");
    $this->assertSame('fw.llama70b', $chosen);
  }

  /**
   * Prompts that exceed a candidate's context window exclude it.
   */
  public function testContextWindowExcludesSmallModels(): void {
    $decider = $this->container->get(RouteDecider::class);
    // ~40k chars => ~10k tokens > qwen's 8192 context; also 'complex' by size.
    $chosen = $decider->resolve('default_chat', str_repeat('palabra ', 5000));
    $this->assertSame('fw.llama70b', $chosen);
  }

  /**
   * Decisions are persisted to the log table.
   */
  public function testDecisionIsLogged(): void {
    $decider = $this->container->get(RouteDecider::class);
    $decider->resolve('default_chat', 'Hola');

    $count = (int) $this->container->get('database')
      ->query('SELECT COUNT(*) FROM {ai_universal_router_log}')->fetchField();
    $this->assertSame(1, $count);
  }

  /**
   * Unknown routes fail loudly.
   */
  public function testUnknownRouteThrows(): void {
    $this->expectException(\RuntimeException::class);
    $this->container->get(RouteDecider::class)->resolve('nope', 'Hola');
  }

}
