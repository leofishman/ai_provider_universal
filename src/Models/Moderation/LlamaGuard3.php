<?php

namespace Drupal\ai_provider_universal\Models\Moderation;

use Drupal\ai\OperationType\Moderation\ModerationResponse;

/**
 * Response parser for Meta's LlamaGuard3 moderation model.
 *
 * LlamaGuard3 outputs "safe" or "unsafe\nSX" where SX is a category code.
 *
 * @see https://huggingface.co/meta-llama/Llama-Guard-3-8B
 */
class LlamaGuard3 {

  /**
   * Category code to human-readable label mapping.
   */
  const CATEGORIES = [
    'S1'  => 'Violent Crimes',
    'S2'  => 'Non-Violent Crimes',
    'S3'  => 'Sex-Related Crimes',
    'S4'  => 'Child Sexual Exploitation',
    'S5'  => 'Defamation',
    'S6'  => 'Specialized Advice',
    'S7'  => 'Privacy',
    'S8'  => 'Intellectual Property',
    'S9'  => 'Indiscriminate Weapons',
    'S10' => 'Hate',
    'S11' => 'Suicide & Self-Harm',
    'S12' => 'Sexual Content',
    'S13' => 'Elections',
    'S14' => 'Code Interpreter Abuse',
  ];

  /**
   * Parses a LlamaGuard3 response into a ModerationResponse.
   *
   * @param string $response
   *   The raw text response from the model.
   *
   * @return \Drupal\ai\OperationType\Moderation\ModerationResponse
   *   The moderation response.
   */
  public static function parse(string $response): ModerationResponse {
    $response = trim($response);

    if (!str_starts_with($response, 'unsafe')) {
      return new ModerationResponse(FALSE);
    }

    // Extract category codes (e.g. "unsafe\nS1" or "unsafe\nS1,S3").
    $codes_part = trim(substr($response, 6));
    $codes = array_filter(array_map('trim', preg_split('/[\n,]+/', $codes_part)));
    $reasoning = [];

    foreach ($codes as $code) {
      $reasoning[] = self::CATEGORIES[$code] ?? $code;
    }

    if (empty($reasoning)) {
      $reasoning[] = 'Unspecified';
    }

    $message = t('Moderation triggered for: @reasons. See https://huggingface.co/meta-llama/Llama-Guard-3-8B for details.', [
      '@reasons' => implode(', ', $reasoning),
    ]);

    return new ModerationResponse(TRUE, $reasoning, $message);
  }

}
