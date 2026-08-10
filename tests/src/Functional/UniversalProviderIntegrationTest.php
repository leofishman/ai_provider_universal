<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the ai_provider_universal module integration.
 *
 * @group ai_provider_universal
 */
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
   * Test server creation and model discovery.
   */
  public function testServerCreationAndDiscovery(): void {
    // Navigate to the server list page.
    $this->drupalGet('/admin/config/ai/providers/universal');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Servers');

    // Click the add server link.
    $this->clickLink('Add');
    $this->assertSession()->statusCodeEquals(200);

    // Fill in the form.
    $edit = [
      'id' => 'test_server',
      'label' => 'Test Server',
      'backend' => 'openai_compatible',
      'host_name' => 'http://host.docker.internal',
      'port' => '8080',
      'timeout' => '60',
    ];
    $this->submitForm($edit, 'Save');

    // Verify server was created.
    $this->assertSession()->pageTextContains('server Test Server has been created.');
    $this->assertSession()->pageTextContains('http://host.docker.internal:8080');

    // Verify models were discovered.
    $this->assertSession()->pageTextContains('llama3-8b');
    $this->assertSession()->pageTextContains('llama3-8b-instruct');
  }

}
