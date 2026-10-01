<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * DeepSeek server backend.
 *
 * DeepSeek speaks the OpenAI protocol at a fixed base URI
 * (https://api.deepseek.com). Its /models endpoint publishes ids only — no
 * pricing, no context — so list prices live in a small table here.
 *
 * DeepSeek bills on a peak / off-peak schedule: peak is 01:00-04:00 and
 * 06:00-10:00 UTC on weekdays; weekends and Chinese public holidays are
 * off-peak all day, and off-peak costs half. The discount is expressed as a
 * multiplier the router applies at decision time (see getPriceMultiplier());
 * stored costs stay at the peak (list) price.
 *
 * @see https://api-docs.deepseek.com/quick_start/pricing
 */
#[AiServerBackend(
  id: 'deepseek',
  label: new TranslatableMarkup('DeepSeek'),
  description: new TranslatableMarkup('DeepSeek (api.deepseek.com): OpenAI-compatible chat and reasoning models. List prices are hardcoded; the off-peak discount (outside 01-04 and 06-10 UTC on weekdays, all day on weekends and Chinese public holidays) is applied automatically when routing on cost.'),
)]
class DeepSeek extends OpenAiCompatible {

  /**
   * Fixed OpenAI-compatible base URI for DeepSeek.
   */
  protected const DEFAULT_BASE_URI = 'https://api.deepseek.com';

  /**
   * Peak (list) prices in USD per 1M tokens (input = cache miss).
   *
   * Verify against https://api-docs.deepseek.com/quick_start/pricing before
   * relying on it — DeepSeek repriced several times; admins can edit costs
   * on the model entity, which discovery never overwrites. Checked
   * 2026-10-01.
   */
  protected const MODEL_METADATA = [
    'deepseek-v4-pro' => [
      'cost_input' => 1.32,
      'cost_output' => 3.96,
      'quality_tier' => 5,
    ],
    'deepseek-flash' => [
      'cost_input' => 0.30,
      'cost_output' => 1.20,
      'quality_tier' => 4,
    ],
  ];

  /**
   * Fraction of the list price charged off-peak, for every model.
   */
  protected const OFF_PEAK = 0.5;

  /**
   * Peak windows in UTC minutes-of-day, [start, end), weekdays only.
   */
  protected const PEAK_WINDOWS = [[60, 240], [360, 600]];

  /**
   * Chinese public holidays (Beijing dates), billed off-peak all day.
   *
   * The fallback when no holiday source is configured or reachable; see
   * holidays(). Make-up workdays need no entry: DeepSeek bills weekends
   * off-peak regardless. Source: State Council notice of 2025-11-04.
   */
  protected const HOLIDAYS = [
    '2026-01-01', '2026-01-02', '2026-01-03',
    '2026-02-15', '2026-02-16', '2026-02-17', '2026-02-18', '2026-02-19',
    '2026-02-20', '2026-02-21', '2026-02-22', '2026-02-23',
    '2026-04-04', '2026-04-05', '2026-04-06',
    '2026-05-01', '2026-05-02', '2026-05-03', '2026-05-04', '2026-05-05',
    '2026-06-19', '2026-06-20', '2026-06-21',
    '2026-09-25', '2026-09-26', '2026-09-27',
    '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05',
    '2026-10-06', '2026-10-07',
  ];

  /**
   * State key caching the remote holiday list.
   */
  protected const HOLIDAYS_STATE = 'ai_provider_universal.deepseek_holidays';

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
    return $this->priceEntry($modelEntry['id'] ?? '') ?? parent::detectModelMetadata($modelEntry);
  }

  /**
   * {@inheritdoc}
   */
  public function getPriceMultiplier(string $rawModelId, ?int $timestamp = NULL): float {
    if ($this->priceEntry($rawModelId) === NULL || !$this->isOffPeak($timestamp ?? time())) {
      return 1.0;
    }
    return self::OFF_PEAK;
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
   * TRUE when the given timestamp is billed at the off-peak rate.
   */
  protected function isOffPeak(int $timestamp): bool {
    $minutes = ((int) gmdate('H', $timestamp)) * 60 + (int) gmdate('i', $timestamp);
    $inWindow = FALSE;
    foreach (self::PEAK_WINDOWS as [$start, $end]) {
      $inWindow = $inWindow || ($minutes >= $start && $minutes < $end);
    }
    if (!$inWindow) {
      return TRUE;
    }
    // Every peak window falls on the same calendar day in Beijing (09:00 to
    // 18:00 there), so the weekday and the holiday are read in Beijing time.
    $beijing = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Asia/Shanghai'));
    return (int) $beijing->format('N') >= 6 || in_array($beijing->format('Y-m-d'), $this->holidays(), TRUE);
  }

  /**
   * The Chinese public holidays, from the configured source or the fallback.
   *
   * A site can point $settings['ai_provider_universal_deepseek_holidays_url']
   * at a JSON list of Beijing dates ("YYYY-MM-DD"), bare or under a
   * "holidays" key. It is fetched at most once a day, only when a call falls
   * in a peak window, and merged with HOLIDAYS; a failed fetch keeps the
   * last good list and is retried the next day.
   *
   * @return string[]
   *   Holiday dates.
   */
  protected function holidays(): array {
    $url = (string) Settings::get('ai_provider_universal_deepseek_holidays_url', '');
    if ($url === '') {
      return self::HOLIDAYS;
    }
    $cached = $this->state->get(self::HOLIDAYS_STATE, ['checked' => 0, 'dates' => []]);
    if (time() - $cached['checked'] > 86400) {
      $cached['checked'] = time();
      try {
        // ponytail: fetched in the routing path, once a day with a 2s cap;
        // move to cron if that first call's latency ever matters.
        $data = Json::decode((string) $this->httpClientFactory->fromOptions(['timeout' => 2, 'connect_timeout' => 2])
          ->request('GET', $url, ['headers' => ['Accept' => 'application/json']])->getBody());
        $dates = is_array($data) ? ($data['holidays'] ?? $data) : NULL;
        if (is_array($dates)) {
          $cached['dates'] = array_values(array_filter($dates, static fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
        }
      }
      catch (\Throwable $e) {
        $this->loggerFactory?->get('ai_provider_universal')->warning(
          'DeepSeek holiday list could not be fetched from @url: @message. Using the last known list.',
          ['@url' => $url, '@message' => $e->getMessage()],
        );
      }
      $this->state->set(self::HOLIDAYS_STATE, $cached);
    }
    return array_unique([...self::HOLIDAYS, ...$cached['dates']]);
  }

}
