<?php

namespace Drupal\ai_provider_universal_factcheck\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\EntityViewsData;

/**
 * Stores the outcome of one content scan (node tab or standalone).
 *
 * Written automatically on every scan; listed by the shipped
 * "Fact check results" view (page + block), which site builders can edit
 * like any other view. Rows are plain audit data — deleting them is safe.
 */
#[ContentEntityType(
  id: 'aip_factcheck_result',
  label: new TranslatableMarkup('Fact check result'),
  label_collection: new TranslatableMarkup('Fact check results'),
  base_table: 'aip_factcheck_result',
  admin_permission: 'view factcheck results',
  handlers: [
    'views_data' => EntityViewsData::class,
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
class FactcheckResult extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['subject'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subject'))
      ->setDescription(new TranslatableMarkup('Node title, URL or "Pasted text".'))
      ->setSetting('max_length', 512);

    $fields['url'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('URL'))
      ->setDescription(new TranslatableMarkup('The scanned URL, when the subject was a URL.'));

    $fields['node'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Node'))
      ->setDescription(new TranslatableMarkup('The scanned node, for Content scan tab runs.'))
      ->setSetting('target_type', 'node');

    $fields['score'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Support score'))
      ->setDescription(new TranslatableMarkup('Fraction of claims supported by evidence (0-1); empty when fact checking was not configured.'));

    $fields['ai_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('AI likelihood (%)'));

    $fields['readability'] = BaseFieldDefinition::create('float')
      ->setLabel(new TranslatableMarkup('Readability'))
      ->setDescription(new TranslatableMarkup('Flesch reading ease.'));

    $fields['plagiarism_matches'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Plagiarism matches'));

    $fields['details'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Details'))
      ->setDescription(new TranslatableMarkup('Full results (claims, verdicts, matches) as stored by the scan.'));

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Run by'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::currentUserId');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Scanned on'));

    return $fields;
  }

  /**
   * Default value callback for uid.
   *
   * @return int[]
   *   The current user id.
   */
  public static function currentUserId(): array {
    return [\Drupal::currentUser()->id()];
  }

}
