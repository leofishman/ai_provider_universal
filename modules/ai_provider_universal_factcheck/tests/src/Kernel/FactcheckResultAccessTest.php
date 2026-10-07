<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\ai_provider_universal_factcheck\Access\FactcheckResultAccessControlHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the view permission does not grant changing results.
 */
#[CoversClass(FactcheckResultAccessControlHandler::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class FactcheckResultAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'views',
    'key',
    'ai',
    'ai_provider_universal',
    'ai_provider_universal_factcheck',
  ];

  /**
   * Viewers can view, only admins can change or delete.
   */
  public function testViewPermissionIsViewOnly(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('aip_factcheck_result');
    // Uid 1 bypasses access checks; take it out of the way.
    $this->createUser();

    $result = $this->container->get('entity_type.manager')
      ->getStorage('aip_factcheck_result')
      ->create(['subject' => 'Pasted text']);
    $result->save();

    $viewer = $this->createUser(['view factcheck results']);
    $this->assertTrue($result->access('view', $viewer));
    $this->assertFalse($result->access('update', $viewer));
    $this->assertFalse($result->access('delete', $viewer));

    $admin = $this->createUser(['administer factcheck settings']);
    $this->assertTrue($result->access('delete', $admin));
  }

}
