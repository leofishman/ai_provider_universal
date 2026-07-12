<?php

declare(strict_types=1);

namespace Drupal\ai_provider_universal_governance\Plugin\AiGuardrail;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiGuardrail;
use Drupal\ai\Guardrail\AiGuardrailPluginBase;
use Drupal\ai\Guardrail\NeedsAiPluginManagerTrait;
use Drupal\ai\Guardrail\NonDeterministicGuardrailInterface;
use Drupal\ai\Guardrail\NonStreamableGuardrailInterface;
use Drupal\ai\Guardrail\Result\GuardrailResultInterface;
use Drupal\ai\Guardrail\Result\PassResult;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\InputInterface;
use Drupal\ai\OperationType\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stops chat traffic whose AI-likelihood score crosses a threshold.
 *
 * Reuses the factcheck submodule's AiDetector (an LLM heuristic, not a
 * trained classifier): on pre-generate it scores the last user message
 * (e.g. AI-written text pasted into a "human submissions" flow), on
 * post-generate the response text. Opt-in and costly — one extra LLM call
 * per evaluation. Only available when the factcheck submodule is enabled
 * and a detector model is configured; a hint, never Art. 50 compliance.
 */
#[AiGuardrail(
  id: 'universal_ai_likelihood',
  label: new TranslatableMarkup('AI likelihood (Universal)'),
  description: new TranslatableMarkup('Stops when the AI-likelihood score of the message or response crosses a threshold. Needs the fact check submodule.'),
)]
class AiLikelihoodGuardrail extends AiGuardrailPluginBase implements ConfigurableInterface, PluginFormInterface, ContainerFactoryPluginInterface, NonDeterministicGuardrailInterface, NonStreamableGuardrailInterface {

  // Required by NonDeterministicGuardrailInterface (LLM-backed plugins).
  use NeedsAiPluginManagerTrait;
  use StringTranslationTrait;

  /**
   * The factcheck AiDetector, NULL when the submodule is not enabled.
   *
   * @var \Drupal\ai_provider_universal_factcheck\Service\AiDetector|null
   */
  protected ?object $detector = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $plugin = new static($configuration, $plugin_id, $plugin_definition);
    // Soft dependency: the service id only resolves when the factcheck
    // submodule is enabled.
    $service = 'Drupal\ai_provider_universal_factcheck\Service\AiDetector';
    if ($container->has($service)) {
      $plugin->detector = $container->get($service);
    }
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->detector !== NULL && $this->detector->isConfigured();
  }

  /**
   * {@inheritdoc}
   */
  public function processInput(InputInterface $input): GuardrailResultInterface {
    if (!$input instanceof ChatInput) {
      return new PassResult('Not a chat input.', $this);
    }
    $messages = $input->getMessages();
    $last = end($messages);
    return $this->score($last instanceof ChatMessage ? $last->getText() : '');
  }

  /**
   * {@inheritdoc}
   */
  public function processOutput(OutputInterface $output): GuardrailResultInterface {
    if (!$output instanceof ChatOutput) {
      return new PassResult('Not a chat output.', $this);
    }
    $normalized = $output->getNormalized();
    return $this->score($normalized instanceof ChatMessage ? $normalized->getText() : '');
  }

  /**
   * Scores a text and stops when the threshold is crossed.
   */
  protected function score(string $text): GuardrailResultInterface {
    if (!$this->isAvailable() || trim($text) === '') {
      return new PassResult('No detector available or nothing to score.', $this);
    }
    $result = $this->detector->detect($text);
    if ($result === NULL) {
      // Detection failure never blocks traffic.
      return new PassResult('AI detection unavailable or failed.', $this);
    }
    $threshold = (int) ($this->configuration['threshold'] ?? 80);
    if ($result['score'] >= $threshold) {
      return new StopResult(
        (string) $this->t('AI-likelihood @score is at or above the threshold @threshold: @rationale', [
          '@score' => $result['score'],
          '@threshold' => $threshold,
          '@rationale' => $result['rationale'],
        ]),
        $this,
        $result,
        $result['score'] / 100,
      );
    }
    return new PassResult('AI-likelihood below threshold.', $this, $result);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['threshold' => 80];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Stop threshold (0–100)'),
      '#min' => 0,
      '#max' => 100,
      '#default_value' => $this->configuration['threshold'] ?? 80,
      '#description' => $this->t('Stop when the estimated AI-likelihood score reaches this value. The detector model is configured in the fact check settings.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->setConfiguration(['threshold' => (int) $form_state->getValue('threshold', 80)]);
  }

}
