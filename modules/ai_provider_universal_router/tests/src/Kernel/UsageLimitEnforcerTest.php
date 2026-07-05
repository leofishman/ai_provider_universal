<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_router\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_universal_router\Service\UsageLimitEnforcer;
use Drupal\ai_provider_universal_router\Event\UsageThresholdEvent;
use Drupal\ai_provider_universal\Service\UsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests usage limit enforcement, grace periods, alerts and events.
 *
 * @group ai_provider_universal
 */
#[CoversClass(UsageLimitEnforcer::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
final class UsageLimitEnforcerTest extends KernelTestBase {

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
   * The limit enforcer service under test.
   */
  protected UsageLimitEnforcer $limitEnforcer;

  /**
   * The usage tracker.
   */
  protected UsageTracker $usageTracker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ai_provider_universal', ['ai_provider_universal_usage']);
    $this->installSchema('ai_provider_universal_router', ['ai_universal_router_log']);

    $this->limitEnforcer = $this->container->get(UsageLimitEnforcer::class);
    $this->usageTracker = $this->container->get(UsageTracker::class);
  }

  /**
   * Helper to create a server and a model associated with it.
   */
  protected function createServerAndModel(string $id, ?int $request_limit = NULL, ?int $grace = NULL, ?int $alert = NULL) {
    $server = $this->container->get('entity_type.manager')
      ->getStorage('ai_universal_server')
      ->create([
        'id' => $id,
        'label' => 'Server ' . $id,
        'daily_request_limit' => $request_limit,
        'limit_grace' => $grace,
        'alert_threshold' => $alert,
      ]);
    $server->save();

    $this->container->get('entity_type.manager')
      ->getStorage('ai_universal_model')
      ->create([
        'id' => $id . '__model',
        'label' => 'Model ' . $id,
        'server_id' => $id,
        'raw_model_id' => 'model',
      ])->save();

    return $server;
  }

  /**
   * Tests enforcer behavior when limits are not configured.
   */
  public function testNoLimits(): void {
    $server = $this->createServerAndModel('no_limits');

    // Even with logged usage, it should not be over limit.
    $this->usageTracker->record('no_limits__model', 1000, 1000);
    $this->assertFalse($this->limitEnforcer->isServerOverLimit($server));
  }

  /**
   * Tests daily request limits, grace periods, and event dispatching.
   */
  public function testRequestLimitsAndGrace(): void {
    // Track dispatched events.
    $events = [];
    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->addListener(UsageThresholdEvent::ALERT, function (UsageThresholdEvent $event) use (&$events) {
      $events['alert'][] = $event;
    });
    $dispatcher->addListener(UsageThresholdEvent::EXHAUSTED, function (UsageThresholdEvent $event) use (&$events) {
      $events['exhausted'][] = $event;
    });

    // 1. Under alert threshold (7 requests recorded on server_under_alert).
    $server1 = $this->createServerAndModel('srv_under_alert', 10, 20, 80);
    for ($i = 0; $i < 7; $i++) {
      $this->usageTracker->record('srv_under_alert__model', 1, 1);
    }
    $this->assertFalse($this->limitEnforcer->isServerOverLimit($server1));
    $this->assertArrayNotHasKey('alert', $events);

    // 2. Alert threshold reached (8 requests recorded on srv_alert_reached).
    $server2 = $this->createServerAndModel('srv_alert_reached', 10, 20, 80);
    for ($i = 0; $i < 8; $i++) {
      $this->usageTracker->record('srv_alert_reached__model', 1, 1);
    }
    $this->assertFalse($this->limitEnforcer->isServerOverLimit($server2));
    $this->assertCount(1, $events['alert'] ?? []);
    $this->assertSame('srv_alert_reached', $events['alert'][0]->serverId);
    $this->assertSame('requests', $events['alert'][0]->metric);

    // 3. Exactly at limit but under grace (10 requests on srv_at_limit).
    $server3 = $this->createServerAndModel('srv_at_limit', 10, 20, 80);
    for ($i = 0; $i < 10; $i++) {
      $this->usageTracker->record('srv_at_limit__model', 1, 1);
    }
    $this->assertFalse($this->limitEnforcer->isServerOverLimit($server3));

    // 4. Over limit but under grace (11 requests on srv_under_grace).
    $server4 = $this->createServerAndModel('srv_under_grace', 10, 20, 80);
    for ($i = 0; $i < 11; $i++) {
      $this->usageTracker->record('srv_under_grace__model', 1, 1);
    }
    $this->assertFalse($this->limitEnforcer->isServerOverLimit($server4));
    $this->assertArrayNotHasKey('exhausted', $events);

    // 5. Reach ceiling (12 requests on srv_at_ceiling).
    $server5 = $this->createServerAndModel('srv_at_ceiling', 10, 20, 80);
    for ($i = 0; $i < 12; $i++) {
      $this->usageTracker->record('srv_at_ceiling__model', 1, 1);
    }
    $this->assertTrue($this->limitEnforcer->isServerOverLimit($server5));
    $this->assertCount(1, $events['exhausted'] ?? []);
    $this->assertSame('srv_at_ceiling', $events['exhausted'][0]->serverId);
  }

}
