<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_universal\Event\AiContentProvenanceEvent;
use Drupal\ai_provider_universal\Event\ModelPostCallEvent;
use Drupal\ai_provider_universal\Service\ProvenanceRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests provenance fact emission (generation and association sources).
 *
 * @group ai_provider_universal
 */
#[CoversClass(ProvenanceRecorder::class)]
#[CoversClass(AiContentProvenanceEvent::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class ProvenanceRecorderTest extends KernelTestBase {

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
   * Provenance events collected by the test listener.
   *
   * @var \Drupal\ai_provider_universal\Event\AiContentProvenanceEvent[]
   */
  protected array $collected = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');

    $this->container->get('entity_type.manager')->getStorage('ai_universal_model')->create([
      'id' => 'local__qwen',
      'label' => 'Local / qwen',
      'server_id' => 'local',
      'raw_model_id' => 'qwen2.5-7b',
      'detected_operation_types' => ['chat'],
    ])->save();

    $this->container->get('event_dispatcher')->addListener(
      AiContentProvenanceEvent::EVENT_NAME,
      function (AiContentProvenanceEvent $event): void {
        $this->collected[] = $event;
      },
    );
  }

  /**
   * Dispatches a post-call event as the provider does after a generation.
   *
   * @param string[] $tags
   *   Optional chat tags.
   */
  protected function dispatchPostCall(array $tags = []): void {
    $this->container->get('event_dispatcher')->dispatch(
      new ModelPostCallEvent('local__qwen', 'chat', 10, 20, 123.4, $tags),
      ModelPostCallEvent::EVENT_NAME,
    );
  }

  /**
   * Disabled by default: generations emit no provenance events.
   */
  public function testDisabledByDefault(): void {
    $this->dispatchPostCall();
    $this->assertSame([], $this->collected);
  }

  /**
   * When enabled, each generation emits one fact with model and server.
   */
  public function testGenerationEmitsFact(): void {
    $this->config('ai_provider_universal.settings')->set('emit_provenance', TRUE)->save();

    $this->dispatchPostCall();

    $this->assertCount(1, $this->collected);
    $event = $this->collected[0];
    $this->assertSame(AiContentProvenanceEvent::SOURCE_GENERATION, $event->getSource());
    $this->assertSame('local__qwen', $event->getModelId());
    $this->assertSame('local', $event->getServerId());
    $this->assertSame('chat', $event->getOperationType());
    $this->assertSame('', $event->getEntityTypeId());
    $this->assertGreaterThan(0, $event->getTimestamp());
  }

  /**
   * Internal tool tags never emit content provenance.
   */
  public function testInternalToolTagsSkipProvenance(): void {
    $this->config('ai_provider_universal.settings')->set('emit_provenance', TRUE)->save();

    $this->dispatchPostCall(['ai_provider_universal_factcheck']);
    $this->assertSame([], $this->collected);

    $this->dispatchPostCall(['route_verifier']);
    $this->assertSame([], $this->collected);

    $this->dispatchPostCall([]);
    $this->assertCount(1, $this->collected);
  }

  /**
   * Association records always dispatch, carrying the entity coordinates.
   */
  public function testRecordAssociation(): void {
    $account = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'author',
    ]);
    $account->save();

    $this->container->get('ai_provider_universal.provenance')
      ->recordAssociation($account, 'field_bio', 'local__qwen', 'chat');

    $this->assertCount(1, $this->collected);
    $event = $this->collected[0];
    $this->assertSame(AiContentProvenanceEvent::SOURCE_ASSOCIATION, $event->getSource());
    $this->assertSame('user', $event->getEntityTypeId());
    $this->assertEquals($account->id(), $event->getEntityId());
    $this->assertSame('field_bio', $event->getFieldName());
    $this->assertSame('local', $event->getServerId());
  }

}
