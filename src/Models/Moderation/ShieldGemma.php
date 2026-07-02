<?php

namespace Drupal\ai_provider_universal\Models\Moderation;

use Drupal\ai\OperationType\Moderation\ModerationResponse;

/**
 * Prompt builder and response parser for Google's ShieldGemma model.
 *
 * ShieldGemma is guideline-conditioned: it evaluates a single user prompt
 * against one safety policy at a time and answers "Yes" (the prompt violates
 * the policy) or "No" (it does not). To cover the whole safety surface the
 * caller runs the model once per policy and flags the content if ANY policy
 * returns "Yes".
 *
 * Its chat template requires a `guideline` variable, so a plain
 * chat.completions request fails with "'guideline' is undefined". Callers
 * therefore build a full prompt with buildPrompt() and post it to
 * /v1/completions instead.
 *
 * @see https://huggingface.co/google/shieldgemma-2b
 */
class ShieldGemma {

  /**
   * Parses a single ShieldGemma response into a ModerationResponse.
   *
   * @param string $response
   *   The raw text response from the model.
   *
   * @return \Drupal\ai\OperationType\Moderation\ModerationResponse
   *   The moderation response.
   */
  public static function parse(string $response): ModerationResponse {
    return new ModerationResponse(self::responseIndicatesViolation($response));
  }

  /**
   * Returns whether a raw ShieldGemma output indicates a violation ("Yes").
   *
   * The model is instructed to answer starting with "Yes" or "No", optionally
   * followed by step-by-step reasoning, so only the leading token matters.
   *
   * @param string $response
   *   The raw text response from the model.
   *
   * @return bool
   *   TRUE if the content violates the policy.
   */
  public static function responseIndicatesViolation(string $response): bool {
    return (bool) preg_match('/^\s*yes\b/i', $response);
  }

  /**
   * The official ShieldGemma safety policies.
   *
   * Verbatim "guideline" values from the model card. Content is flagged if it
   * violates ANY of them. Keyed by a short machine-friendly category name used
   * in the moderation information array.
   *
   * @return array<string, string>
   *   Category name keyed to its guideline text.
   */
  public static function getDefaultGuidelines(): array {
    return [
      'harassment' => '"No Harassment": The prompt shall not contain or seek generation of content that is malicious, intimidating, bullying, or abusive content targeting another individual (e.g., physical threats, denial of tragic events, disparaging victims of violence).',
      'hate_speech' => '"No Hate Speech": The prompt shall not contain or seek generation of content that targeting identity and/or protected attributes (e.g., racial slurs, promotion of discrimination, calls to violence against protected groups) or dehumanizing/belittling/vilifying on the bases of race, ethnicity, religion, disability, age, nationality, veteran status, sexual orientation, gender, gender identity, caste, or any other protected status. We do allow content that includes a reference to a protected individual or group in a positive manner.',
      'dangerous_content' => '"No Dangerous Content": The prompt shall not contain or seek generation of content that harming oneself and/or others (e.g., accessing or building firearms and explosive devices, promotion of terrorism, instructions for suicide).',
      'sexually_explicit' => '"No Sexually Explicit Information": The prompt shall not contain or seek generation of content that contains references to sexual acts or other lewd content (e.g., sexually graphic descriptions, content aimed at causing arousal). Medical or scientific terms related to human anatomy or sex education are permitted.',
    ];
  }

  /**
   * Builds the exact prompt ShieldGemma expects for a content + guideline pair.
   *
   * This mirrors the model's chat template verbatim, minus the leading <bos>
   * token which the /v1/completions endpoint prepends. Posting this raw prompt
   * bypasses the Jinja template's required `guideline` variable.
   *
   * @param string $userContent
   *   The user content to moderate.
   * @param string $guideline
   *   One safety policy from getDefaultGuidelines().
   *
   * @return string
   *   The full prompt to send to /v1/completions.
   */
  public static function buildPrompt(string $userContent, string $guideline): string {
    $q = trim($userContent);
    $g = trim($guideline);
    return "You are a policy expert trying to help determine whether a user prompt is in violation of the defined safety policies.\n\n"
      . "<start_of_turn>\nHuman Question: {$q}\n<end_of_turn>\n\n"
      . "Our safety principle is defined in the below:\n\n"
      . "* {$g}\n\n"
      . "===\n\n"
      . "Does the human question violate the above principle? Your answer must start with 'Yes' or 'No'. And then walk through step by step to be sure we answer correctly.\n\n";
  }

}
