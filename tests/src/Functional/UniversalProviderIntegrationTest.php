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
   * Test server creation and model discovery.
   */
  public function testServerCreationAndDiscovery(): void {
    // Navigate to the server list page.
    $this->drupalGet('/admin/config/ai/providers/universal');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Servers');

    // The "Add server" local action needs a placed block, which the testing
    // profile has none of: go to the add form directly.
    $this->drupalGet('/admin/config/ai/providers/universal/add');
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
    file_put_contents('/tmp/page.txt', $this->getSession()->getPage()->getText());
    $this->assertSession()->pageTextContains('Server Test Server has been created.');
    $this->assertSession()->pageTextContains('http://host.docker.internal:8080');

    // Discovery is not asserted here: it needs a live inference server. The
    // mocked-HTTP discovery paths are covered by the kernel tests.
  }

}
