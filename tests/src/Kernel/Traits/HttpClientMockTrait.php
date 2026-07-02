<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Kernel\Traits;

use Drupal\Core\Http\ClientFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Trait to help Kernel tests mock HTTP responses using Guzzle MockHandler.
 *
 * Why MockHandler?
 * - The module uses Drupal's ClientFactory (the proper HTTP API).
 * - ClientFactory ultimately creates Guzzle clients.
 * - MockHandler is the standard way (from guzzlehttp/guzzle) to inject
 *   predetermined responses for tests without any network access.
 * - This is especially useful when testing code paths that go through
 *   the OpenAI SDK or make direct HTTP calls (rerank, custom moderation, etc.).
 */
trait HttpClientMockTrait {

  /**
   * Replaces 'http_client_factory' with a MockHandler-backed Guzzle client.
   *
   * @param array<int, ResponseInterface|\Exception> $responses
   *   List of responses (or exceptions) to return in order.
   */
  protected function mockHttpClientResponses(array $responses): void {
    $mockHandler = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mockHandler);
    $guzzleClient = new GuzzleClient(['handler' => $handlerStack]);

    $mockFactory = $this->createMock(ClientFactory::class);
    $mockFactory->method('fromOptions')->willReturn($guzzleClient);

    $this->container->set('http_client_factory', $mockFactory);
  }

  /**
   * Convenience helper to create a single JSON response for /v1/models.
   *
   * @param array $data
   *   The value for the 'data' key in the OpenAI-style list response.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   A 200 JSON response wrapping $data in an OpenAI-style list envelope.
   */
  protected function createModelsListResponse(array $data): ResponseInterface {
    $payload = [
      'object' => 'list',
      'data' => $data,
    ];

    return new Response(200, ['Content-Type' => 'application/json'], json_encode($payload));
  }

}
