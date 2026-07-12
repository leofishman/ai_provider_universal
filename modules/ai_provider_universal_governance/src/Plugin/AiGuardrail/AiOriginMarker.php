<?php

declare(strict_types=1);

namespace Drupal\ai_provider_universal_governance\Plugin\AiGuardrail;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiGuardrail;

/**
 * Appends a machine-readable AI-origin marker to chat responses.
 *
 * Same mechanics as the disclosure suffix but the default text is an HTML
 * comment, invisible to readers yet machine-detectable: it survives
 * copy-paste into a body field, where render-side tooling (or a scan
 * profile) can pick it up as a provenance hint. The digitalSourceType value
 * follows the IPTC vocabulary used for AI Act Art. 50(2)-style marking.
 * This does not replace the upstream model provider's own marking duty.
 */
#[AiGuardrail(
  id: 'universal_ai_origin_marker',
  label: new TranslatableMarkup('AI origin marker (Universal)'),
  description: new TranslatableMarkup('Appends an invisible machine-readable AI-origin marker to every chat response.'),
)]
class AiOriginMarker extends DisclosureSuffix {

  /**
   * {@inheritdoc}
   */
  protected function separator(): string {
    return "\n";
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultSuffixText(): string {
    return '<!-- ai-origin: generated; digitalSourceType=trainedAlgorithmicMedia -->';
  }

}
