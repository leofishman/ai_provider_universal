<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\EventSubscriber;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_provider_universal\EventSubscriber\GuardrailDefaultsSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests default/route Guardrail set attach on pre-generate.
 *
 * @group ai_provider_universal
 */
#[CoversClass(GuardrailDefaultsSubscriber::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class GuardrailDefaultsSubscriberTest extends KernelTestBase {

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
    $set_storage = $this->container->get('entity_type.manager')->getStorage('ai_guardrail_set');
    foreach (['default_set', 'route_set', 'caller_set'] as $id) {
      $set_storage->create([
        'id' => $id,
        'label' => $id,
        'description' => '',
        'stop_threshold' => 1.0,
      ])->save();
    }
  }

  /**
   * Dispatches a pre-generate event and returns the resulting input.
   *
   * @param string[] $tags
   *   Optional chat tags (e.g. factcheck tool tag).
   */
  protected function dispatch(ChatInput $input, string $provider_id = 'universal', string $model_id = 'some_model', array $tags = []): ChatInput {
    $event = new PreGenerateResponseEvent(
      requestThreadId: 'test-thread',
      providerId: $provider_id,
      operationType: 'chat',
      configuration: [],
      input: $input,
      modelId: $model_id,
      tags: $tags,
    );
    $this->container->get('event_dispatcher')->dispatch($event, PreGenerateResponseEvent::EVENT_NAME);
    return $event->getInput();
  }

  /**
   * Fresh single-message chat input.
   */
  protected function chatInput(): ChatInput {
    return new ChatInput([new ChatMessage('user', 'Hello')]);
  }

  /**
   * Ids of the sets attached to the input, on both ai 1.3 and 1.4 APIs.
   *
   * @return string[]
   *   The attached guardrail set ids.
   */
  protected function attachedSetIds(ChatInput $input): array {
    if (method_exists($input, 'getGuardrailSets')) {
      return array_keys($input->getGuardrailSets());
    }
    $set = $input->getGuardrailSet();
    return $set !== NULL ? [$set->id()] : [];
  }

  /**
   * Attaches a set to the input, on both ai 1.3 and 1.4 APIs.
   */
  protected function attachSet(ChatInput $input, string $set_id): void {
    $set = $this->container->get('entity_type.manager')
      ->getStorage('ai_guardrail_set')->load($set_id);
    method_exists($input, 'addGuardrailSet')
      ? $input->addGuardrailSet($set)
      : $input->setGuardrailSet($set);
  }

  /**
   * The configured default set is attached when the caller sent none.
   */
  public function testDefaultSetAttached(): void {
    $this->config('ai_provider_universal.settings')->set('default_guardrail_set', 'default_set')->save();

    $input = $this->dispatch($this->chatInput());
    $this->assertSame(['default_set'], $this->attachedSetIds($input));
  }

  /**
   * Empty configuration leaves the input untouched (beta behaviour).
   */
  public function testEmptyConfigurationIsNoOp(): void {
    $input = $this->dispatch($this->chatInput());
    $this->assertSame([], $this->attachedSetIds($input));
  }

  /**
   * A caller-attached set is never overwritten or amended.
   */
  public function testCallerSetWins(): void {
    $this->config('ai_provider_universal.settings')->set('default_guardrail_set', 'default_set')->save();

    $input = $this->chatInput();
    $this->attachSet($input, 'caller_set');

    $result = $this->dispatch($input);
    $this->assertSame(['caller_set'], $this->attachedSetIds($result));
  }

  /**
   * Calls served by other providers are ignored.
   */
  public function testOtherProviderIgnored(): void {
    $this->config('ai_provider_universal.settings')->set('default_guardrail_set', 'default_set')->save();

    $input = $this->dispatch($this->chatInput(), 'openai');
    $this->assertSame([], $this->attachedSetIds($input));
  }

  /**
   * A set on the addressed smart route beats the module-wide default.
   */
  public function testRouteSetOverridesDefault(): void {
    $this->config('ai_provider_universal.settings')->set('default_guardrail_set', 'default_set')->save();
    $this->container->get('entity_type.manager')->getStorage('ai_universal_route')->create([
      'id' => 'cheap',
      'label' => 'Cheap',
      'guardrail_set' => 'route_set',
    ])->save();

    $input = $this->dispatch($this->chatInput(), 'universal', 'route__cheap');
    $this->assertSame(['route_set'], $this->attachedSetIds($input));
  }

  /**
   * A route without its own set falls through to the module default.
   */
  public function testRouteWithoutSetFallsBackToDefault(): void {
    $this->config('ai_provider_universal.settings')->set('default_guardrail_set', 'default_set')->save();
    $this->container->get('entity_type.manager')->getStorage('ai_universal_route')->create([
      'id' => 'plain',
      'label' => 'Plain',
    ])->save();

    $input = $this->dispatch($this->chatInput(), 'universal', 'route__plain');
    $this->assertSame(['default_set'], $this->attachedSetIds($input));
  }

  /**
   * A configured id pointing to a deleted set is a silent no-op.
   */
  public function testMissingSetIsNoOp(): void {
    $this->config('ai_provider_universal.settings')->set('default_guardrail_set', 'nonexistent')->save();

    $input = $this->dispatch($this->chatInput());
    $this->assertSame([], $this->attachedSetIds($input));
  }

  /**
   * Factcheck (and other internal tool) tags never get the default set.
   */
  public function testInternalToolTagsSkipAttach(): void {
    $this->config('ai_provider_universal.settings')->set('default_guardrail_set', 'default_set')->save();

    foreach (['ai_provider_universal_factcheck', 'complexity_classifier', 'route_verifier'] as $tag) {
      $input = $this->dispatch($this->chatInput(), 'universal', 'some_model', ['chat', $tag]);
      $this->assertSame([], $this->attachedSetIds($input), "Tag $tag must skip default Guardrail attach");
    }
  }

}
