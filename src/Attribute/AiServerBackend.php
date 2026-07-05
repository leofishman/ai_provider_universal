<?php

namespace Drupal\ai_provider_universal\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a AiServerBackend plugin attribute.
 *
 * A server backend encapsulates everything protocol-specific about talking
 * to a remote inference server: how to list its models, how to detect each
 * model's operation types, and how to build the request base URI.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AiServerBackend extends AttributeBase {

  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
  ) {}

}
