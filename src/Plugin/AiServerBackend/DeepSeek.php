<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * DeepSeek server backend.
 *
 * DeepSeek speaks the OpenAI protocol at a fixed base URI
 * (https://api.deepseek.com). Its /models endpoint publishes ids only — no
 * pricing, no context — so list prices live in a small table here.
 *
 * DeepSeek bills on a peak / off-peak schedule: standard price between
 * 00:30 and 16:30 UTC, discounted outside that window. The discount is a
 * flat percentage per model applied to both input and output tokens, so it
 * is expressed as a single multiplier the router applies at decision time
 * (see getPriceMultiplier()); stored costs stay at list price.
 */
#[AiServerBackend(
  id: 'deepseek',
  label: new TranslatableMarkup('DeepSeek'),
  description: new TranslatableMarkup('DeepSeek (api.deepseek.com): OpenAI-compatible chat and reasoning models. List prices are hardcoded; the off-peak discount (16:30-00:30 UTC) is applied automatically when routing on cost.'),
)]
class DeepSeek extends OpenAiCompatible {

  /**
   * Fixed OpenAI-compatible base URI for DeepSeek.
   */
  protected const DEFAULT_BASE_URI = 'https://api.deepseek.com';

  /**
   * List prices in USD per 1M tokens (input = cache miss) and off-peak rate.
   *
   * 'off_peak' is the fraction of the list price charged during the discount
   * window. Verify against https://api-docs.deepseek.com/quick_start/pricing
   * before relying on it — DeepSeek repriced several times; admins can edit
   * costs on the model entity, which discovery never overwrites.
   */
  protected const MODEL_METADATA = [
    'deepseek-reasoner' => [
      'cost_input' => 0.55,
      'cost_output' => 2.19,
      'quality_tier' => 5,
      'context_length' => 64000,
      'off_peak' => 0.25,
    ],
    'deepseek-chat' => [
      'cost_input' => 0.27,
      'cost_output' => 1.10,
      'quality_tier' => 4,
      'context_length' => 64000,
      'off_peak' => 0.50,
    ],
  ];

  /**
   * Off-peak window in UTC minutes-of-day: [start, end), wrapping midnight.
   */
  protected const OFF_PEAK_START = 16 * 60 + 30;
  protected const OFF_PEAK_END = 30;

  /**
   * {@inheritdoc}
   */
  public function getBaseUri(AiUniversalServerInterface $server): string {
    // DeepSeek always uses the fixed public endpoint (host field ignored).
    return self::DEFAULT_BASE_URI;
  }

  /**
   * {@inheritdoc}
   */
  public function detectModelMetadata(array $modelEntry): array {
    $meta = $this->priceEntry($modelEntry['id'] ?? '');
    if ($meta === NULL) {
      return parent::detectModelMetadata($modelEntry);
    }
    unset($meta['off_peak']);
    return $meta;
  }

  /**
   * {@inheritdoc}
   */
  public function getPriceMultiplier(string $rawModelId, ?int $timestamp = NULL): float {
    $meta = $this->priceEntry($rawModelId);
    if ($meta === NULL || !$this->isOffPeak($timestamp ?? time())) {
      return 1.0;
    }
    return (float) $meta['off_peak'];
  }

  /**
   * Looks up the price table entry for a raw model id, NULL when unknown.
   */
  protected function priceEntry(string $rawModelId): ?array {
    $id = strtolower($rawModelId);
    foreach (self::MODEL_METADATA as $pattern => $meta) {
      if (str_contains($id, $pattern)) {
        return $meta;
      }
    }
    return NULL;
  }

  /**
   * TRUE when the given UTC timestamp falls in the discount window.
   */
  protected function isOffPeak(int $timestamp): bool {
    $minutes = ((int) gmdate('H', $timestamp)) * 60 + (int) gmdate('i', $timestamp);
    return $minutes >= self::OFF_PEAK_START || $minutes < self::OFF_PEAK_END;
  }

}
