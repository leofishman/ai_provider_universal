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
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\InputInterface;
use Drupal\ai\OperationType\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stops chat responses whose claims fail fact verification (post-generate).
 *
 * Reuses the factcheck submodule's FactChecker pipeline (claim extraction,
 * evidence cascade, verdicts) on the response text. Opt-in and costly —
 * several LLM/evidence calls per evaluation; what runs is governed by the
 * fact check profile setting (fast/balanced/thorough), including the max
 * claims cap. Only available when the factcheck submodule is enabled and a
 * checker model is configured. For routed answers prefer the smart-route
 * fact-check escalation, which retries on a stronger model instead of
 * stopping.
 */
#[AiGuardrail(
  id: 'universal_factcheck',
  label: new TranslatableMarkup('Fact check (Universal)'),
  description: new TranslatableMarkup('Stops responses whose claim-support score falls below a minimum. Needs the fact check submodule.'),
)]
class FactcheckGuardrail extends AiGuardrailPluginBase implements ConfigurableInterface, PluginFormInterface, ContainerFactoryPluginInterface, NonDeterministicGuardrailInterface, NonStreamableGuardrailInterface {

  use NeedsAiPluginManagerTrait;
  use StringTranslationTrait;

  /**
   * The factcheck FactChecker, NULL when the submodule is not enabled.
   *
   * @var \Drupal\ai_provider_universal_factcheck\Service\FactChecker|null
   */
  protected ?object $checker = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $plugin = new static($configuration, $plugin_id, $plugin_definition);
    // Soft dependency: the alias only resolves when the factcheck submodule
    // is enabled.
    if ($container->has('ai_provider_universal_factcheck.checker')) {
      $plugin->checker = $container->get('ai_provider_universal_factcheck.checker');
    }
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->checker !== NULL && $this->checker->isConfigured();
  }

  /**
   * {@inheritdoc}
   */
  public function processInput(InputInterface $input): GuardrailResultInterface {
    return new PassResult('Fact check only acts on output.', $this);
  }

  /**
   * {@inheritdoc}
   */
  public function processOutput(OutputInterface $output): GuardrailResultInterface {
    if (!$output instanceof ChatOutput) {
      return new PassResult('Not a chat output.', $this);
    }
    $normalized = $output->getNormalized();
    $text = $normalized instanceof ChatMessage ? $normalized->getText() : '';
    if (!$this->isAvailable() || trim($text) === '') {
      return new PassResult('No checker available or nothing to verify.', $this);
    }

    $result = $this->checker->verify('', $text);
    $min = (float) ($this->configuration['min_score'] ?? 0.6);
    if ($result['score'] < $min) {
      return new StopResult(
        (string) $this->t('Claim-support score @score is below the minimum @min.', [
          '@score' => round($result['score'], 2),
          '@min' => $min,
        ]),
        $this,
        $result,
        1 - $result['score'],
      );
    }
    return new PassResult('Claims sufficiently supported.', $this, $result);
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
    return ['min_score' => 0.6];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['min_score'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum claim-support score (0–1)'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#default_value' => $this->configuration['min_score'] ?? 0.6,
      '#description' => $this->t('Stop when the fact check support score falls below this value. Evidence sources, profile depth and the max-claims cap are configured in the fact check settings.'),
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
    $this->setConfiguration(['min_score' => (float) $form_state->getValue('min_score', 0.6)]);
  }

}
