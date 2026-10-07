<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal\Unit\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Plugin\AiServerBackend\OpenAiCompatible;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Hugging Face pipeline tag lookup guard.
 *
 * @group ai_provider_universal
 */
#[CoversClass(OpenAiCompatible::class)]
#[Group('ai_provider_universal')]
final class OpenAiCompatibleTest extends TestCase {

  /**
   * A repo name that is not "org/name" never reaches the HTTP client.
   */
  public function testMalformedRepoIsNotLookedUp(): void {
    $factory = $this->createMock(ClientFactory::class);
    $factory->expects($this->never())->method('fromOptions');
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn([]);
    $backend = new OpenAiCompatible([], 'openai_compatible', [], $factory, $state);

    $lookup = new \ReflectionMethod($backend, 'getHfPipelineTag');
    foreach (['../../admin', 'org/name?x=1', 'org/name/../x', 'noslash', 'org/name#frag'] as $repo) {
      $this->assertNull($lookup->invoke($backend, $repo), $repo);
    }
  }

}
