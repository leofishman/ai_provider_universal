<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the ai_provider_universal module integration.
 *
 * @group ai_provider_universal
 */
#[RunTestsInSeparateProcesses]
class UniversalProviderIntegrationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai_provider_universal',
    'key',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser([
      'access administration pages',
      'administer ai providers',
    ]);
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Tests the add-server form: it loads, and refuses unreachable servers.
   *
   * Creating a server for real needs a reachable inference endpoint (the form
   * validates connectivity), so the happy path is not exercised here; the
   * discovery and catalog logic is covered by the kernel tests with mocked
   * HTTP.
   */
  public function testAddServerFormRejectsUnreachableServer(): void {
    $this->drupalGet('/admin/config/ai/providers/universal');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Servers');

    // The "Add server" local action needs a placed block, which the testing
    // profile has none of: go to the add form directly.
    $this->drupalGet('/admin/config/ai/providers/universal/add');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'id' => 'test_server',
      'label' => 'Test Server',
      'backend' => 'openai_compatible',
      'host_name' => 'http://host.docker.internal',
      'port' => '8080',
      'timeout' => '60',
    ], 'Save');

    $this->assertSession()->pageTextContains('Could not connect to the server.');
    $this->assertNull(
      \Drupal::entityTypeManager()->getStorage('ai_universal_server')->load('test_server'),
    );
  }

}
