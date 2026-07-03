<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Estimates how likely a text is AI-generated, via a chat model.
 *
 * This is a heuristic judgement by an LLM, not a trained detector like
 * Originality.ai's classifier: useful as a hint, not as proof. The detector
 * model is configurable ('detector_model', empty = the checker model).
 */
class AiDetector {

  protected const DETECT_PROMPT = <<<PROMPT
You are an AI-generated-text detector. Estimate the probability (0-100) that
the TEXT below was written by an AI language model rather than a human.
Consider repetitive phrasing, generic hedging, uniform sentence rhythm and
lack of personal voice. Respond ONLY with JSON:
{"score": <0-100>, "rationale": "<one short sentence>"}

TEXT:
%s
PROMPT;

  public function __construct(
    protected AiProviderPluginManager $providerManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether a model is available for detection.
   */
  public function isConfigured(): bool {
    $settings = $this->configFactory->get('ai_provider_universal_factcheck.settings');
    return (bool) ($settings->get('detector_model') ?: $settings->get('checker_model'));
  }

  /**
   * Scores a text.
   *
   * @return array{score: int, rationale: string}|null
   *   AI-likelihood 0-100 and a one-line rationale, or NULL when no model is
   *   configured or the call/parse failed.
   */
  public function detect(string $text): ?array {
    $settings = $this->configFactory->get('ai_provider_universal_factcheck.settings');
    $model = (string) ($settings->get('detector_model') ?: $settings->get('checker_model'));
    if (!$model) {
      return NULL;
    }

    try {
      $provider = $this->providerManager->createInstance('universal');
      // ponytail: 6000 chars is plenty for a style judgement and caps tokens.
      $prompt = sprintf(self::DETECT_PROMPT, mb_substr($text, 0, 6000));
      $output = $provider->chat(new ChatInput([new ChatMessage('user', $prompt)]), $model, ['ai_provider_universal_factcheck']);
      $raw = $output->getNormalized()->getText();
    }
    catch (\Throwable $e) {
      $this->logger->error('AI detection call failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }

    if (!preg_match('/\{.*\}/s', $raw, $match)) {
      return NULL;
    }
    $data = json_decode($match[0], TRUE);
    if (!is_array($data) || !isset($data['score']) || !is_numeric($data['score'])) {
      return NULL;
    }

    return [
      'score' => (int) max(0, min(100, (int) $data['score'])),
      'rationale' => is_string($data['rationale'] ?? NULL) ? $data['rationale'] : '',
    ];
  }

}
