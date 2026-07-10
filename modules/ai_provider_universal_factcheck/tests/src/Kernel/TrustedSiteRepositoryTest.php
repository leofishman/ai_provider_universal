<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_universal_factcheck\Service\TrustedSiteRepository;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * TrustedSiteRepository against real trusted_site nodes.
 *
 * The repository is instantiated directly (it only needs the entity type
 * manager), so the factcheck module and its AI dependencies stay disabled.
 */
#[CoversClass(TrustedSiteRepository::class)]
#[Group('ai_provider_universal')]
#[RunTestsInSeparateProcesses]
class TrustedSiteRepositoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node', 'field', 'text'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'trusted_site', 'name' => 'Trusted site'])->save();

    $fields = [
      'field_domain' => 'string',
      'field_reputation' => 'integer',
      'field_bias' => 'string',
      'field_owner' => 'string',
    ];
    foreach ($fields as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'trusted_site',
      ])->save();
    }
  }

  /**
   * Creates a published trusted_site node.
   */
  protected function createSite(string $domain, int $reputation, array $extra = []): void {
    Node::create([
      'type' => 'trusted_site',
      'title' => $domain,
      'field_domain' => $domain,
      'field_reputation' => $reputation,
      'status' => 1,
    ] + $extra)->save();
  }

  /**
   * Curated include/exclude lists and profiles come from published nodes.
   */
  public function testCurationMapFromNodes(): void {
    // Full URL with mixed case normalizes to a bare lowercase host.
    $this->createSite('https://Good.Example/some/path', 8, ['field_owner' => 'Acme Media', 'field_bias' => 'lean_left']);
    $this->createSite('better.example', 10);
    $this->createSite('bad.example', -5);
    // Unpublished curation must not count.
    Node::create([
      'type' => 'trusted_site',
      'title' => 'draft',
      'field_domain' => 'draft.example',
      'field_reputation' => 9,
      'status' => 0,
    ])->save();

    $repository = new TrustedSiteRepository($this->container->get('entity_type.manager'), $this->container->get('cache.default'));

    // Positive domains, best reputation first.
    $this->assertSame(['better.example', 'good.example'], $repository->includeDomains());
    $this->assertSame(['bad.example'], $repository->excludeDomains());

    $this->assertSame(8, $repository->reputation('good.example'));
    $this->assertSame(8, $repository->reputation('GOOD.example'));
    $this->assertSame(0, $repository->reputation('unknown.example'));
    $this->assertSame(0, $repository->reputation('draft.example'));

    $profile = $repository->profile('good.example');
    $this->assertSame('Acme Media', $profile['owner']);
    $this->assertSame('lean_left', $profile['bias']);
    $this->assertSame([], $profile['assessments']);
  }

  /**
   * Without any trusted_site nodes curation is simply off.
   */
  public function testNoCurationMeansEmptyLists(): void {
    $repository = new TrustedSiteRepository($this->container->get('entity_type.manager'), $this->container->get('cache.default'));
    $this->assertSame([], $repository->includeDomains());
    $this->assertSame([], $repository->excludeDomains());
    $this->assertSame(0, $repository->reputation('anything.example'));
  }

  /**
   * The persistent cache is invalidated when trusted_site nodes change.
   */
  public function testPersistentCacheInvalidation(): void {
    $etm = $this->container->get('entity_type.manager');
    $cache = $this->container->get('cache.default');

    // Prime the persistent cache with an empty curation map.
    (new TrustedSiteRepository($etm, $cache))->profileMap();

    $etm->getStorage('node')->create([
      'type' => 'trusted_site',
      'title' => 'New source',
      'field_domain' => 'new.example',
      'field_reputation' => 5,
      'status' => 1,
    ])->save();

    // A fresh instance (no per-request cache) must see the new node: saving
    // it invalidated the node_list:trusted_site tag.
    $repository = new TrustedSiteRepository($etm, $cache);
    $this->assertSame(5, $repository->reputation('new.example'));
  }

}
