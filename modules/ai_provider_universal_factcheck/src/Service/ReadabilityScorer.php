<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

/**
 * Computes a Flesch reading-ease score for plain text. No AI, no config.
 *
 * Syllables are estimated by counting vowel groups per word — the usual
 * approximation; good enough to place a text in a readability band, not a
 * linguistically exact count.
 */
class ReadabilityScorer {

  /**
   * Scores a plain-text passage.
   *
   * @return array{score: float, band: string, words: int, sentences: int}|null
   *   Flesch reading-ease (clamped to 0-100), a coarse band
   *   ('very easy'|'easy'|'standard'|'difficult'|'very difficult'), and the
   *   counts used. NULL when the text is too short to be meaningful.
   */
  public function score(string $text): ?array {
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $wordCount = count($words);
    if ($wordCount < 10) {
      return NULL;
    }

    $sentenceCount = max(1, preg_match_all('/[.!?…]+(?:\s|$)/u', $text));
    $syllables = 0;
    foreach ($words as $word) {
      $syllables += max(1, preg_match_all('/[aeiouyáéíóúü]+/iu', $word));
    }

    $score = 206.835
      - 1.015 * ($wordCount / $sentenceCount)
      - 84.6 * ($syllables / $wordCount);
    $score = max(0.0, min(100.0, $score));

    return [
      'score' => round($score, 1),
      'band' => match (TRUE) {
        $score >= 80 => 'very easy',
        $score >= 60 => 'easy',
        $score >= 40 => 'standard',
        $score >= 20 => 'difficult',
        default => 'very difficult',
      },
      'words' => $wordCount,
      'sentences' => $sentenceCount,
    ];
  }

}
