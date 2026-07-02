<?php

namespace Drupal\ai_provider_universal_router\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_universal_router\Form\UniversalRouteForm;
use Drupal\ai_provider_universal_router\UniversalRouteListBuilder;

/**
 * A smart route: a virtual model that picks a real model per request.
 *
 * Routes appear in the AI settings model dropdown as "route__<id>". At
 * request time the RouteDecider classifies the prompt (simple/complex) and
 * picks the cheapest candidate whose quality tier satisfies the class.
 */
#[ConfigEntityType(
  id: 'universal_route',
  label: new TranslatableMarkup('Smart Route'),
  label_collection: new TranslatableMarkup('Smart Routes'),
  label_singular: new TranslatableMarkup('smart route'),
  label_plural: new TranslatableMarkup('smart routes'),
  handlers: [
    'list_builder' => UniversalRouteListBuilder::class,
    'form' => [
      'add' => UniversalRouteForm::class,
      'edit' => UniversalRouteForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  config_prefix: 'route',
  admin_permission: 'administer ai providers',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  config_export: [
    'id',
    'label',
    'operation_type',
    'candidates',
    'simple_tier',
    'complex_tier',
    'factcheck',
    'factcheck_min_score',
  ],
  links: [
    'add-form' => '/admin/config/ai/providers/universal/routes/add',
    'edit-form' => '/admin/config/ai/providers/universal/routes/{universal_route}',
    'delete-form' => '/admin/config/ai/providers/universal/routes/{universal_route}/delete',
    'collection' => '/admin/config/ai/providers/universal/routes',
  ],
)]
class UniversalRoute extends ConfigEntityBase implements UniversalRouteInterface {

  /**
   * The route machine name.
   *
   * @var string
   */
  protected string $id;

  /**
   * The human-readable label.
   *
   * @var string
   */
  protected string $label;

  /**
   * Operation type this route serves.
   *
   * @var string
   */
  protected string $operation_type = 'chat';

  /**
   * Candidate universal_model ids. Empty = all capable models.
   *
   * @var string[]
   */
  protected array $candidates = [];

  /**
   * Minimum quality tier for simple prompts.
   *
   * @var int
   */
  protected int $simple_tier = 2;

  /**
   * Minimum quality tier for complex prompts.
   *
   * @var int
   */
  protected int $complex_tier = 4;

  /**
   * Whether answers on this route are fact-checked (chat only).
   *
   * @var bool
   */
  protected bool $factcheck = FALSE;

  /**
   * Minimum support score; below it the request escalates.
   *
   * @var float
   */
  protected float $factcheck_min_score = 0.7;

  /**
   * {@inheritdoc}
   */
  public function isFactcheckEnabled(): bool {
    return $this->factcheck;
  }

  /**
   * {@inheritdoc}
   */
  public function getFactcheckMinScore(): float {
    return $this->factcheck_min_score;
  }

  /**
   * {@inheritdoc}
   */
  public function getOperationType(): string {
    return $this->operation_type ?: 'chat';
  }

  /**
   * {@inheritdoc}
   */
  public function getCandidates(): array {
    return $this->candidates ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSimpleTier(): int {
    return $this->simple_tier ?: 2;
  }

  /**
   * {@inheritdoc}
   */
  public function getComplexTier(): int {
    return $this->complex_tier ?: 4;
  }

}
