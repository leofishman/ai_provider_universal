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
    // Matches deepseek-flash and versioned ids such as deepseek-v4.1-flash.
    'flash' => [
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
   * Used by the local calculation, the fallback when the status service is
   * disabled or unreachable (see remotePeak()). Make-up workdays need no
   * entry: DeepSeek bills weekends off-peak regardless. Source: State
   * Council notice of 2025-11-04.
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
   * Default peak status service (https://seekpeak.dev, by SteffenR).
   */
  protected const STATUS_URL = 'https://api.seekpeak.dev/v1/status';

  /**
   * State key caching the last status answer.
   */
  protected const STATUS_STATE = 'ai_provider_universal.deepseek_status';

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
   *
   * The status service answers for the current moment, holidays included;
   * any other moment, or a service that does not answer, is computed
   * locally from the schedule and the built-in holiday list.
   */
  protected function isOffPeak(int $timestamp): bool {
    $peak = $this->remotePeak($timestamp);
    if ($peak !== NULL) {
      return !$peak;
    }
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
    return (int) $beijing->format('N') >= 6 || in_array($beijing->format('Y-m-d'), self::HOLIDAYS, TRUE);
  }

  /**
   * Whether DeepSeek bills peak right now, per the status service.
   *
   * The service answers {"peak": bool, "nextTransition": {"at": ISO 8601}},
   * so one answer stays valid until the next transition: a handful of
   * requests a day. A failure is retried after an hour, meanwhile the local
   * calculation applies. $settings['ai_provider_universal_deepseek_status_url']
   * points at another service with the same answer; '' disables it.
   *
   * @param int $timestamp
   *   The moment asked about.
   *
   * @return bool|null
   *   TRUE at peak, FALSE off-peak, NULL when the service cannot tell (it is
   *   disabled or unreachable, or $timestamp is not now).
   */
  protected function remotePeak(int $timestamp): ?bool {
    $url = (string) Settings::get('ai_provider_universal_deepseek_status_url', self::STATUS_URL);
    $now = time();
    if ($url === '' || abs($timestamp - $now) > 60) {
      return NULL;
    }
    $cached = $this->state->get(self::STATUS_STATE) ?: [];
    if (isset($cached['peak']) && $timestamp >= $cached['checked'] && $timestamp < $cached['until']) {
      return $cached['peak'];
    }
    if ($now < ($cached['retry'] ?? 0)) {
      return NULL;
    }
    try {
      // ponytail: fetched in the routing path with a 5s cap, at most once
      // per transition; move to cron if that call's latency ever matters.
      $data = Json::decode((string) $this->httpClientFactory->fromOptions(['timeout' => 5, 'connect_timeout' => 3])
        ->request('GET', $url, ['headers' => ['Accept' => 'application/json']])->getBody());
      $until = strtotime((string) ($data['nextTransition']['at'] ?? ''));
      if (!is_bool($data['peak'] ?? NULL) || !$until || $until <= $now) {
        throw new \UnexpectedValueException('unexpected answer');
      }
      $this->state->set(self::STATUS_STATE, ['peak' => $data['peak'], 'checked' => $now, 'until' => $until]);
      return $data['peak'];
    }
    catch (\Throwable $e) {
      $this->state->set(self::STATUS_STATE, ['retry' => $now + 3600]);
      $this->loggerFactory?->get('ai_provider_universal')->warning(
        'DeepSeek peak status could not be read from @url: @message. Using the built-in schedule for an hour.',
        ['@url' => $url, '@message' => $e->getMessage()],
      );
      return NULL;
    }
  }

}
