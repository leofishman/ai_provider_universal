<?php

namespace Drupal\ai_provider_universal_factcheck\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_universal_factcheck\Form\ScanProfileForm;
use Drupal\ai_provider_universal_factcheck\ScanProfileListBuilder;
use Drupal\node\NodeInterface;

/**
 * A scan profile: which nodes get which async checks on save.
 *
 * Matching nodes are enqueued on insert/update (cheap filters only, no LLM
 * in the request) and scanned later by the aip_content_review queue worker,
 * which persists an aip_factcheck_result row and, per the profile's event
 * setting, dispatches a content review event for ECA/Workflow. Saving is
 * never blocked. See docs/content-governance.md, Surface B.
 */
#[ConfigEntityType(
  id: 'aip_scan_profile',
  label: new TranslatableMarkup('Scan profile'),
  label_collection: new TranslatableMarkup('Scan profiles'),
  label_singular: new TranslatableMarkup('scan profile'),
  label_plural: new TranslatableMarkup('scan profiles'),
  handlers: [
    'list_builder' => ScanProfileListBuilder::class,
    'form' => [
      'add' => ScanProfileForm::class,
      'edit' => ScanProfileForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  config_prefix: 'scan_profile',
  admin_permission: 'administer factcheck settings',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  config_export: [
    'id',
    'label',
    'status',
    'bundles',
    'operations',
    'published_only',
    'cooldown',
    'checks',
    'event_on',
  ],
  links: [
    'add-form' => '/admin/config/ai/factcheck/scan-profiles/add',
    'edit-form' => '/admin/config/ai/factcheck/scan-profiles/{aip_scan_profile}',
    'delete-form' => '/admin/config/ai/factcheck/scan-profiles/{aip_scan_profile}/delete',
    'collection' => '/admin/config/ai/factcheck/scan-profiles',
  ],
)]
class ScanProfile extends ConfigEntityBase implements ScanProfileInterface {

  /**
   * The profile machine name.
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
   * Node bundles to scan.
   *
   * @var string[]
   */
  protected array $bundles = [];

  /**
   * Operations that enqueue: insert and/or update.
   *
   * @var string[]
   */
  protected array $operations = ['insert', 'update'];

  /**
   * Scan published nodes only.
   *
   * @var bool
   */
  protected bool $published_only = TRUE;

  /**
   * Seconds before the same node can be enqueued again.
   *
   * @var int
   */
  protected int $cooldown = 3600;

  /**
   * Check settings keyed by check id (missing = disabled).
   *
   * @var array<string, array>
   */
  protected array $checks = [];

  /**
   * When the content review event fires: always | threshold | never.
   *
   * @var string
   */
  protected string $event_on = 'threshold';

  /**
   * {@inheritdoc}
   */
  public function getBundles(): array {
    return $this->bundles ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(): array {
    return $this->operations ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function isPublishedOnly(): bool {
    return $this->published_only;
  }

  /**
   * {@inheritdoc}
   */
  public function getCooldown(): int {
    return max(0, $this->cooldown);
  }

  /**
   * {@inheritdoc}
   */
  public function getChecks(): array {
    return $this->checks ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getEventOn(): string {
    return in_array($this->event_on, ['always', 'threshold', 'never'], TRUE)
      ? $this->event_on
      : 'threshold';
  }

  /**
   * {@inheritdoc}
   */
  public function appliesTo(NodeInterface $node, string $operation): bool {
    return $this->status()
      && in_array($operation, $this->getOperations(), TRUE)
      && in_array($node->bundle(), $this->getBundles(), TRUE)
      && (!$this->isPublishedOnly() || $node->isPublished());
  }

}
