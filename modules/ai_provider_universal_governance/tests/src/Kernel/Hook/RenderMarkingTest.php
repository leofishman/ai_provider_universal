<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_governance\Kernel\Hook;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_universal_governance\Hook\AiProviderUniversalGovernanceHooks;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests render marking: ai-origin meta tag and visible disclosure label.
 *
 * @group ai_provider_universal
 */
#[CoversClass(AiProviderUniversalGovernanceHooks::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class RenderMarkingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'node',
    'key',
    'ai',
    'ai_provider_universal',
    'ai_provider_universal_governance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    $fields = [
      'field_ai_origin' => ['type' => 'list_string', 'settings' => ['allowed_values' => ['generated' => 'Generated', 'assisted' => 'Assisted', 'human' => 'Human', 'unknown' => 'Unknown']]],
      'field_ai_disclosure_req' => ['type' => 'boolean', 'settings' => []],
      'field_ai_exemption' => ['type' => 'list_string', 'settings' => ['allowed_values' => ['editorial_responsibility' => 'Editorial', 'artistic_creative_satirical' => 'Artistic', 'assistive_edit' => 'Assistive']]],
    ];
    foreach ($fields as $name => $def) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $def['type'],
        'settings' => $def['settings'],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'article',
      ])->save();
    }
  }

  /**
   * Creates a node and returns the hook's render additions for it.
   */
  protected function view(array $values, string $view_mode = 'full'): array {
    $node = Node::create(['type' => 'article', 'title' => 'Test'] + $values);
    $node->save();
    assert($node instanceof NodeInterface);
    $build = [];
    $display = $this->container->get('entity_display.repository')->getViewDisplay('node', 'article', $view_mode);
    $hooks = $this->container->get(AiProviderUniversalGovernanceHooks::class);
    $hooks->nodeView($build, $node, $display, $view_mode);
    return $build;
  }

  /**
   * Extracts the ai-origin meta content from a build, or NULL.
   */
  protected function metaContent(array $build): ?string {
    foreach ($build['#attached']['html_head'] ?? [] as [$element, $key]) {
      if ($key === 'ai_provider_universal_governance_origin') {
        return $element['#attributes']['content'];
      }
    }
    return NULL;
  }

  /**
   * Generated + disclosure required: meta tag and banner label.
   */
  public function testGeneratedWithDisclosure(): void {
    $build = $this->view([
      'field_ai_origin' => 'generated',
      'field_ai_disclosure_req' => 1,
    ]);
    $this->assertSame('generated; digitalSourceType=trainedAlgorithmicMedia', $this->metaContent($build));
    $this->assertArrayHasKey('ai_disclosure_label', $build);
    $this->assertSame(-100, $build['ai_disclosure_label']['#weight']);
    $this->assertSame(['ai-disclosure-label'], $build['ai_disclosure_label']['#attributes']['class']);
  }

  /**
   * Assisted without the disclosure flag: meta tag only.
   */
  public function testAssistedWithoutDisclosure(): void {
    $build = $this->view(['field_ai_origin' => 'assisted']);
    $this->assertSame('assisted; digitalSourceType=compositeWithTrainedAlgorithmicMedia', $this->metaContent($build));
    $this->assertArrayNotHasKey('ai_disclosure_label', $build);
  }

  /**
   * Assistive edit exemption: no marking at all (Art. 50(2)).
   */
  public function testAssistiveEditExemption(): void {
    $build = $this->view([
      'field_ai_origin' => 'generated',
      'field_ai_disclosure_req' => 1,
      'field_ai_exemption' => 'assistive_edit',
    ]);
    $this->assertSame([], $build);
  }

  /**
   * Editorial responsibility: meta stays, label dropped.
   */
  public function testEditorialResponsibilityExemption(): void {
    $build = $this->view([
      'field_ai_origin' => 'generated',
      'field_ai_disclosure_req' => 1,
      'field_ai_exemption' => 'editorial_responsibility',
    ]);
    $this->assertNotNull($this->metaContent($build));
    $this->assertArrayNotHasKey('ai_disclosure_label', $build);
  }

  /**
   * Artistic exemption: adapted credits line at the bottom, not a banner.
   */
  public function testArtisticExemptionAdaptsDisclosure(): void {
    $build = $this->view([
      'field_ai_origin' => 'generated',
      'field_ai_disclosure_req' => 1,
      'field_ai_exemption' => 'artistic_creative_satirical',
    ]);
    $this->assertNotNull($this->metaContent($build));
    $this->assertSame(100, $build['ai_disclosure_label']['#weight']);
    $this->assertSame(['ai-disclosure-credits'], $build['ai_disclosure_label']['#attributes']['class']);
  }

  /**
   * Human origin and empty origin: untouched build.
   */
  public function testHumanOrEmptyOriginUntouched(): void {
    $this->assertSame([], $this->view(['field_ai_origin' => 'human']));
    $this->assertSame([], $this->view([]));
  }

  /**
   * Non-canonical view modes are not marked.
   */
  public function testTeaserUntouched(): void {
    $build = $this->view([
      'field_ai_origin' => 'generated',
      'field_ai_disclosure_req' => 1,
    ], 'teaser');
    $this->assertSame([], $build);
  }

}
