<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_router\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_provider_universal\Kernel\Traits\HttpClientMockTrait;
use Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests decisions asked through a smart route.
 *
 * @group ai_provider_universal
 */
#[CoversClass(UniversalProvider::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class RoutedDecisionTest extends KernelTestBase {

  use HttpClientMockTrait;

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
   * Tests that a route resolving to a decision model sends typed questions.
   *
   * The question shape follows the winning candidate, so the route has to be
   * resolved before it is chosen — a route id is never a decision model
   * itself.
   */
  public function testDecideResolvesRouteBeforeChoosingQuestionShape(): void {
    $etm = $this->container->get('entity_type.manager');
    $etm->getStorage('ai_universal_server')->create([
      'id' => 'jev',
      'label' => 'TypeSafe',
      'backend' => 'typesafe',
      'host_name' => '',
      'port' => '',
      'timeout' => 60,
    ])->save();
    $etm->getStorage('ai_universal_model')->create([
      'id' => 'jev.latest',
      'label' => 'Jev',
      'server_id' => 'jev',
      'raw_model_id' => 'jev-latest',
      'detected_operation_types' => ['chat'],
    ])->save();
    $this->installSchema('ai_provider_universal', ['ai_provider_universal_usage']);
    $this->installSchema('ai_provider_universal_router', ['ai_universal_router_log']);
    $etm->getStorage('ai_universal_route')->create([
      'id' => 'decisions',
      'label' => 'Decisions',
      'operation_type' => 'chat',
      'candidates' => ['jev.latest'],
    ])->save();

    $this->mockHttpClientResponses([
      new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'model' => 'jev-1.13.0',
        'answers' => ['urgent' => ['type' => 'noul', 'noul' => 0.7]],
        'usage' => ['input_tokens' => 9, 'output_tokens' => 1],
      ])),
    ]);
    $requests = [];
    $this->container->get('http_client_factory')->fromOptions([])->getConfig('handler')
      ->push(function (callable $handler) use (&$requests) {
        return function ($request, array $options) use ($handler, &$requests) {
          $requests[] = $request;
          return $handler($request, $options);
        };
      });

    $answers = $this->container->get('ai.provider')->createInstance('universal')
      ->decide('route.decisions', 'Refund today or I cancel.', [
        'urgent' => ['type' => 'noul', 'instructions' => 'The message is urgent'],
      ]);

    // Typed questions on the wire, not a JSON-please prompt.
    $payload = json_decode((string) $requests[0]->getBody(), TRUE);
    $this->assertSame('noul', $payload['questions']['urgent']['type']);
    $this->assertSame(0.7, $answers['urgent']['noul']);
  }

}
