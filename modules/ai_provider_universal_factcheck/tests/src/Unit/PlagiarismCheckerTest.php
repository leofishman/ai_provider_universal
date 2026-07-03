<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Service\PlagiarismChecker;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\ai_provider_universal_factcheck\Service\PlagiarismChecker
 * @group ai_provider_universal
 */
class PlagiarismCheckerTest extends UnitTestCase {

  /**
   * Requests captured by the Guzzle history middleware.
   */
  protected array $history = [];

  /**
   * A checker with scripted Serper responses.
   */
  protected function buildChecker(array $responses, string $keyId = 'serper'): PlagiarismChecker {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn($keyId);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('sk-serper');
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->willReturnCallback(
      static fn (string $id) => $id === 'serper' ? $key : NULL,
    );

    return new PlagiarismChecker(
      new Client(['handler' => $stack]),
      $configFactory,
      $keyRepository,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * @covers ::check
   * @covers ::isConfigured
   */
  public function testNoKeyMeansUnavailable(): void {
    $checker = $this->buildChecker([], keyId: '');
    $this->assertFalse($checker->isConfigured());
    $this->assertNull($checker->check('Any long text to look up on the web for verbatim copies of it.'));
  }

  /**
   * @covers ::check
   */
  public function testLongSentencesAreSearchedAsExactPhrases(): void {
    $sentence = 'This exceptionally distinctive sentence about pgvector-backed evidence retrieval is long enough to be searched.';
    $checker = $this->buildChecker([
      new Response(200, [], json_encode([
        'organic' => [
          ['link' => 'https://copy.example/post', 'title' => 'A copy', 'snippet' => 'the same words'],
        ],
      ])),
    ]);

    $matches = $checker->check("Short one. $sentence");

    $this->assertCount(1, $matches);
    $this->assertSame($sentence, $matches[0]['sentence']);
    $this->assertSame('https://copy.example/post', $matches[0]['url']);

    $this->assertCount(1, $this->history);
    $payload = json_decode((string) $this->history[0]['request']->getBody(), TRUE);
    $this->assertStringStartsWith('"', $payload['q']);
    $this->assertStringEndsWith('"', $payload['q']);
    $this->assertSame('sk-serper', $this->history[0]['request']->getHeaderLine('X-API-KEY'));
  }

  /**
   * Texts with only short sentences trigger no searches at all.
   *
   * @covers ::check
   */
  public function testShortSentencesAreNotSearched(): void {
    $checker = $this->buildChecker([]);
    $this->assertSame([], $checker->check('Short. Also short. Still short.'));
    $this->assertCount(0, $this->history);
  }

}
