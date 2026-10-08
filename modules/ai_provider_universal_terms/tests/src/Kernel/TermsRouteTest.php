<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_terms\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the analyze route's request requirements.
 */
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class TermsRouteTest extends KernelTestBase {

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
    'ai_provider_universal_terms',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['ai_provider_universal_terms']);
    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous'])
      ->grantPermission('use terms analyzer')
      ->save();
  }

  /**
   * Posts a body to the route and returns the response status.
   */
  protected function post(string $body, string $contentType): int {
    $request = Request::create('/api/terms/analyze?_format=json', 'POST', [], [], [], ['CONTENT_TYPE' => $contentType], $body);
    return $this->container->get('http_kernel')->handle($request)->getStatusCode();
  }

  /**
   * A JSON body reaches the controller; a form-encoded one does not.
   */
  public function testOnlyJsonBodiesAreAccepted(): void {
    // No model is configured: reaching the controller answers 503.
    $this->assertSame(503, $this->post('{"text": "Terms"}', 'application/json'));
    // What a cross-site form can send without a CORS preflight.
    $this->assertSame(415, $this->post('{"text": "Terms"}', 'text/plain'));
  }

  /**
   * Requests over the hourly limit are refused before any work.
   */
  public function testHourlyLimit(): void {
    // A model that does not exist: counted requests fail at the call (502).
    $this->config('ai_provider_universal_terms.settings')
      ->set('model', 'nowhere.model')
      ->set('requests_per_hour', 2)
      ->save();
    $this->assertSame(502, $this->post('{"text": "Terms"}', 'application/json'));
    $this->assertSame(502, $this->post('{"text": "Terms"}', 'application/json'));
    $this->assertSame(429, $this->post('{"text": "Terms"}', 'application/json'));
  }

}
