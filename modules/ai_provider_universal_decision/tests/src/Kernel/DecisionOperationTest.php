<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_decision\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ai_provider_universal\Kernel\Traits\HttpClientMockTrait;
use Drupal\ai\Exception\AiMissingFeatureException;
use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\Value\ChoiceAnswer;
use Drupal\ai\OperationType\Decision\Value\NoulAnswer;
use Drupal\ai_provider_universal_decision\DecisionUniversalProvider;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests AI core's Decision operation against a System One server.
 *
 * @group ai_provider_universal
 */
#[CoversClass(DecisionUniversalProvider::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class DecisionOperationTest extends KernelTestBase {

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
    'ai_provider_universal_decision',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!interface_exists('Drupal\ai\OperationType\Decision\DecisionInterface')) {
      $this->markTestSkipped('The Decision operation needs AI 1.6 or newer.');
    }
    $this->installSchema('ai_provider_universal', ['ai_provider_universal_usage']);
    $etm = $this->container->get('entity_type.manager');
    $etm->getStorage('ai_universal_server')->create([
      'id' => 'laya',
      'label' => 'Laya',
      'backend' => 'typesafe',
      'host_name' => 'http://laya.test',
      'port' => '',
      'timeout' => 60,
    ])->save();
    $etm->getStorage('ai_universal_model')->create([
      'id' => 'laya.laya',
      'label' => 'Laya',
      'server_id' => 'laya',
      'raw_model_id' => 'laya',
      'detected_operation_types' => ['chat', 'decision'],
    ])->save();
  }

  /**
   * Tests typed questions in, typed answers out, through AI core's proxy.
   */
  public function testDecisionReturnsTypedAnswers(): void {
    // Wire shape as Laya returns it (2026-10-01).
    $this->mockHttpClientResponses([
      new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'model' => 'laya',
        'answers' => [
          'churn' => ['type' => 'noul', 'noul' => 0.855, 'confidence' => 0.855],
          'topic' => [
            'type' => 'choice',
            'choice' => 'billing',
            'probabilities' => ['billing' => 0.9127, 'bug' => 0.0771, 'other' => 0.0102],
            'confidence' => 0.7017,
          ],
        ],
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
      ])),
    ]);

    $provider = $this->container->get('ai.provider')->createInstance('universal');
    $this->assertInstanceOf(DecisionUniversalProvider::class, $provider->getPlugin());
    $this->assertContains('decision', $provider->getSupportedOperationTypes());

    $output = $provider->decision(new DecisionInput('Charged twice, fix it now or I leave.', [
      'churn' => ['type' => 'noul', 'instructions' => 'The customer is about to cancel.'],
      'topic' => [
        'type' => 'choice',
        'instructions' => 'What is the message about?',
        'criteria' => ['billing' => 'payments', 'bug' => 'software defect', 'other' => NULL],
      ],
    ]), 'laya.laya', ['test']);

    $response = $output->getNormalized();
    $this->assertInstanceOf(NoulAnswer::class, $response->getAnswer('churn'));
    $this->assertEqualsWithDelta(0.855, $response->getNoul('churn')->getProbability(), 0.0001);
    $this->assertInstanceOf(ChoiceAnswer::class, $response->getAnswer('topic'));
    $this->assertSame('billing', $response->getChoice('topic')->getChoice());
    $this->assertSame('laya', $response->getModel());
  }

  /**
   * Tests that Laya refuses noul criteria before any request is sent.
   *
   * They narrow its scores, so it does not declare them; a portable question
   * set must fail loudly rather than score differently.
   */
  public function testLayaRejectsNoulCriteria(): void {
    $this->mockHttpClientResponses([]);
    $this->expectException(AiMissingFeatureException::class);
    $this->container->get('ai.provider')->createInstance('universal')->decision(new DecisionInput('Refund now.', [
      'refund' => [
        'type' => 'noul',
        'instructions' => 'The customer wants a refund.',
        'criteria' => ['true' => 'asks for money back', 'false' => 'anything else'],
      ],
    ]), 'laya.laya');
  }

  /**
   * Tests that a structured state is sent as JSON, not as text.
   */
  public function testStructuredStateIsSentAsJson(): void {
    $this->mockHttpClientResponses([
      new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'model' => 'laya',
        'answers' => ['refund' => ['type' => 'noul', 'noul' => 0.9]],
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

    $state = ['message' => 'Charged twice, refund now.', 'plan' => 'pro'];
    $output = $this->container->get('ai.provider')->createInstance('universal')->decision(new DecisionInput($state, [
      'refund' => ['type' => 'noul', 'instructions' => 'The customer wants a refund.'],
    ]), 'laya.laya');

    $this->assertTrue($output->getNormalized()->getNoul('refund')->isLikely());
    $this->assertSame($state, json_decode((string) $requests[0]->getBody(), TRUE)['state']);
  }

  /**
   * Tests that only decision models are offered for the operation.
   */
  public function testOnlyDecisionModelsAreConfigured(): void {
    $this->container->get('entity_type.manager')->getStorage('ai_universal_model')->create([
      'id' => 'laya.chatty',
      'label' => 'Chatty',
      'server_id' => 'laya',
      'raw_model_id' => 'chatty',
      'detected_operation_types' => ['chat'],
    ])->save();
    $models = $this->container->get('ai.provider')->createInstance('universal')->getConfiguredModels('decision');
    $this->assertSame(['laya.laya'], array_keys($models));
  }

}
