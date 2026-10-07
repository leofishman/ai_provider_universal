<?php

namespace Drupal\ai_provider_universal\Plugin\AiServerBackend;

use Drupal\ai_provider_universal\Attribute\AiServerBackend;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * OpenAI Decisions API backend (gpt-6-luna).
 *
 * The same kind of model as System One (TypeSafe Jev, Laya): typed questions
 * about an input, answered with calibrated probabilities instead of text.
 * Only the wire format differs, so this extends the typesafe backend and
 * translates at the edge; everything built on decision models (AI core's
 * Decision operation, smart routes, fact check, the router's classifier)
 * works with it unchanged.
 *
 * | System One                | OpenAI Decisions                      |
 * |---------------------------|---------------------------------------|
 * | POST /v1/systemone        | POST /v1/decisions                    |
 * | state                     | input                                 |
 * | questions: {id: {...}}    | questions: [{name: id, ...}]          |
 * | noul                      | predicate                             |
 * | choice criteria {k: desc} | choices [{value: k, description}]     |
 * | score criteria [desc...]  | levels [{label, description}]         |
 * | answers: {id: {...}}      | answers: [{name: id, ...}]            |
 *
 * @see https://developers.openai.com/api/docs/guides/decisions
 */
#[AiServerBackend(
  id: 'openai_decisions',
  label: new TranslatableMarkup('OpenAI Decisions'),
  description: new TranslatableMarkup('OpenAI Decisions API (gpt-6-luna): typed decisions — yes/no probability, choice, score — with calibrated confidence, not text generation. Same question format and features as TypeSafe (Jev). Requires an OpenAI API key.'),
)]
class OpenAiDecisions extends TypeSafe {

  /**
   * {@inheritdoc}
   */
  protected const DEFAULT_BASE_URI = 'https://api.openai.com/v1';

  /**
   * {@inheritdoc}
   */
  protected const ENDPOINT = '/decisions';

  /**
   * {@inheritdoc}
   *
   * Not documented; AI core's default tolerance applies.
   */
  public const DECISION_PRECISION = NULL;

  /**
   * The gpt-6-luna list price: USD 0.10 per million input tokens, no output.
   */
  protected const LUNA_PRICE = ['cost_input' => 0.10, 'cost_output' => 0.0];

  /**
   * {@inheritdoc}
   *
   * The catalog lists every OpenAI model; only Luna answers decisions.
   */
  public function listModels(AiUniversalServerInterface $server): array {
    // ponytail: prefix match on the one decision model documented today;
    // a capability flag in /v1/models is the upgrade if OpenAI adds one.
    return array_values(array_filter(
      parent::listModels($server),
      static fn (array $model): bool => str_starts_with($model['id'], 'gpt-6-luna'),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function detectModelMetadata(array $modelEntry): array {
    return ['quality_tier' => 1] + static::LUNA_PRICE;
  }

  /**
   * {@inheritdoc}
   *
   * Translates the System One payload to OpenAI's and the answers back, so
   * callers see the same shape whichever backend answered.
   */
  protected function request(AiUniversalServerInterface $server, array $payload): array {
    $questions = $payload['questions'];
    $data = parent::request($server, [
      'model' => $payload['model'],
      'input' => is_string($payload['state']) ? $payload['state'] : Json::encode($payload['state']),
      'questions' => array_map(
        fn ($id) => $this->toOpenAiQuestion((string) $id, $questions[$id]),
        array_keys($questions),
      ),
    ]);

    $answers = [];
    foreach ($data['answers'] ?? [] as $answer) {
      $id = (string) ($answer['name'] ?? '');
      if (!isset($questions[$id])) {
        continue;
      }
      if (($answer['type'] ?? '') === 'refusal') {
        // Left unanswered: the Decision operation reports it, and a route
        // fails over to its next candidate.
        $this->loggerFactory?->get('ai_provider_universal')->warning('@model refused to answer question @id.', [
          '@model' => $payload['model'],
          '@id' => $id,
        ]);
        continue;
      }
      $answers[$id] = $this->fromOpenAiAnswer($answer, $questions[$id]);
    }
    $data['answers'] = $answers;
    return $data;
  }

  /**
   * Maps a System One question to an OpenAI Decisions question.
   */
  protected function toOpenAiQuestion(string $id, array $question): array {
    $type = $question['type'] ?? '';
    $criteria = $question['criteria'] ?? NULL;
    $out = [
      'type' => $type === 'noul' ? 'predicate' : $type,
      'name' => $id,
      'instructions' => $this->text($question['instructions'] ?? ''),
    ];
    if ($type === 'choice' && is_array($criteria)) {
      foreach ($criteria as $value => $description) {
        $out['choices'][] = ['value' => (string) $value] + ($description === NULL ? [] : ['description' => $this->text($description)]);
      }
    }
    elseif ($type === 'score' && is_array($criteria)) {
      // ponytail: levels are labelled by index, which is what the answer's
      // probabilities are keyed by; score questions are not verified against
      // the live API yet.
      foreach (array_values($criteria) as $level => $description) {
        $out['levels'][] = ['label' => (string) $level, 'description' => $this->text($description)];
      }
    }
    elseif ($type === 'noul' && $criteria !== NULL) {
      $this->loggerFactory?->get('ai_provider_universal')->warning('Dropped "criteria" from yes/no question @id: the OpenAI Decisions API has no such field.', ['@id' => $id]);
    }
    return $out;
  }

  /**
   * Maps an OpenAI Decisions answer to a System One answer.
   */
  protected function fromOpenAiAnswer(array $answer, array $question): array {
    $probabilities = [];
    foreach ($answer['probabilities'] ?? [] as $index => $entry) {
      $probabilities[$entry['value'] ?? $index] = $entry['probability'] ?? NULL;
    }
    return match ($answer['type'] ?? '') {
      'predicate' => ['type' => 'noul', 'noul' => $answer['probability'] ?? NULL],
      'choice' => [
        'type' => 'choice',
        'choice' => $answer['choice'] ?? NULL,
        'probabilities' => $probabilities,
        'confidence' => $answer['confidence'] ?? NULL,
      ],
      'score' => [
        'type' => 'score',
        'score' => $answer['score'] ?? NULL,
        'legend' => array_values((array) ($question['criteria'] ?? [])),
        'probabilities' => $probabilities,
        'confidence' => $answer['confidence'] ?? NULL,
      ],
      default => $answer,
    };
  }

  /**
   * Instructions and descriptions may be structured; the API takes text.
   */
  protected function text(mixed $value): string {
    return is_string($value) ? $value : Json::encode($value);
  }

}
