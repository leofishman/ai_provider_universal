<?php

namespace Drupal\ai_provider_universal_decision;

use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiBrokenOutputException;
use Drupal\ai\Exception\AiMissingFeatureException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\DecisionInterface;
use Drupal\ai\OperationType\Decision\DecisionOutput;
use Drupal\ai\OperationType\Decision\DecisionRequestValidator;
use Drupal\ai\OperationType\Decision\DecisionResponse;
use Drupal\ai\OperationType\Decision\Value\AnswerFactory;
use Drupal\ai\OperationType\Decision\Value\AnswerPrecision;
use Drupal\ai\OperationType\Decision\Value\DecisionCapabilities;
use Drupal\ai_provider_universal\Entity\AiUniversalModelInterface;
use Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider;
use Drupal\Component\Serialization\Json;

/**
 * The Universal provider, serving AI core's Decision operation too.
 *
 * Swapped in for the "universal" plugin class by
 * \Drupal\ai_provider_universal_decision\Hook\DecisionHooks.
 *
 * Decisions ride the System One chat bridge (see the typesafe backend), so
 * the pre-call gate, usage limits, usage recording and route failover apply
 * to them unchanged. Only decision models answer: a chat model has no
 * calibrated probabilities to fill a typed answer with.
 */
class DecisionUniversalProvider extends UniversalProvider implements DecisionInterface {

  /**
   * What every System One model accepts, measured on Laya.
   *
   * Laya also takes noul criteria on the wire, but they narrow its scores
   * (see TypeSafe::supportedQuestions()), so only Jev declares them.
   */
  const SYSTEM_ONE_CAPABILITIES = [
    AiModelCapability::DecisionNoul,
    AiModelCapability::DecisionChoice,
    AiModelCapability::DecisionScore,
    AiModelCapability::DecisionMultipleQuestions,
    AiModelCapability::DecisionStructuredInstructions,
    AiModelCapability::DecisionChoiceDescriptions,
    AiModelCapability::DecisionStructuredChoiceDescriptions,
    AiModelCapability::DecisionStructuredScoreLevels,
  ];

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return array_values(array_unique([...parent::getSupportedOperationTypes(), 'decision']));
  }

  /**
   * {@inheritdoc}
   */
  public function getDecisionCapabilities(string $model_id): DecisionCapabilities {
    // ponytail: structured state is not declared (the chat bridge carries the
    // state as text) nor are image files; a smart route declares the baseline
    // every System One model shares, its candidates are checked per call.
    if (str_starts_with($model_id, 'route.')) {
      return new DecisionCapabilities(self::SYSTEM_ONE_CAPABILITIES);
    }
    if (!$this->isDecisionModel($model_id)) {
      throw new AiMissingFeatureException(sprintf('Model "%s" is not a decision model: only models on a System One server answer typed questions.', $model_id));
    }
    $model = $this->entityTypeManager->getStorage('ai_universal_model')->load($model_id);
    $jev = $model instanceof AiUniversalModelInterface && str_starts_with(strtolower($model->getRawModelId()), 'jev');
    return new DecisionCapabilities($jev
      ? [
        ...self::SYSTEM_ONE_CAPABILITIES,
        AiModelCapability::DecisionNoulCriteria,
        AiModelCapability::DecisionStructuredNoulCriteria,
      ]
      : self::SYSTEM_ONE_CAPABILITIES);
  }

  /**
   * {@inheritdoc}
   */
  public function validateDecisionInput(DecisionInput $input, string $model_id): void {
    DecisionRequestValidator::validate($input, $this->getDecisionCapabilities($model_id), 'universal', $model_id);
  }

  /**
   * {@inheritdoc}
   */
  public function decision(DecisionInput $input, string $model_id, array $tags = []): DecisionOutput {
    $route = $model_id;
    $state = $input->getState();
    $text = is_string($state) ? $state : Json::encode($state);
    $model_id = $this->resolveRoutedModel($route, $text, 'decision');
    try {
      return $this->decisionOn($input, $model_id, $tags);
    }
    catch (\Throwable $e) {
      return $this->decisionOn($input, $this->nextRouteCandidate($route, $model_id, $text, $e, 'decision'), $tags);
    }
  }

  /**
   * Asks one concrete model; see ::decision().
   */
  protected function decisionOn(DecisionInput $input, string $model_id, array $tags): DecisionOutput {
    // Each candidate of a route declares its own capabilities.
    $this->validateDecisionInput($input, $model_id);

    $questions = array_map(static fn ($question) => $question->toArray(), $input->getQuestions());
    $output = $this->doChat(new ChatInput([
      new ChatMessage('system', Json::encode($questions)),
      new ChatMessage('user', $input->getState()),
    ]), $model_id, $tags);
    $data = $output->getRawOutput();

    // System One rounds probabilities to four decimals.
    $precision = new AnswerPrecision(4);
    $answers = [];
    foreach (array_keys($questions) as $id) {
      if (!isset($data['answers'][$id]) || !is_array($data['answers'][$id])) {
        throw new AiBrokenOutputException(sprintf('Model "%s" returned no answer for question "%s".', $model_id, $id));
      }
      try {
        $answers[$id] = AnswerFactory::fromArray($data['answers'][$id], $precision);
      }
      catch (\InvalidArgumentException $e) {
        throw new AiBrokenOutputException(sprintf('Model "%s" returned an invalid answer for question "%s": %s', $model_id, $id, $e->getMessage()), 0, $e);
      }
    }

    return new DecisionOutput(
      new DecisionResponse($answers, (string) ($data['model'] ?? $model_id), $output->getTokenUsage()),
      $data,
      ['model' => $data['model'] ?? NULL],
    );
  }

  /**
   * {@inheritdoc}
   *
   * Decision capabilities are answered by ::getDecisionCapabilities(); the
   * rest by the parent.
   */
  protected function filterByCapabilities(array $options, array $capabilities): array {
    $decision = array_filter($capabilities, static fn (AiModelCapability $capability) => $capability->getBaseOperationType() === 'decision');
    foreach ($decision ? array_keys($options) : [] as $id) {
      try {
        if (!$this->getDecisionCapabilities($id)->supports($decision)) {
          unset($options[$id]);
        }
      }
      catch (AiMissingFeatureException) {
        unset($options[$id]);
      }
    }
    return parent::filterByCapabilities($options, $capabilities);
  }

}
