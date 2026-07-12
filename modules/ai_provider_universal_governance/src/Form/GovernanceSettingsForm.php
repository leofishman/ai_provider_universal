<?php

namespace Drupal\ai_provider_universal_governance\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Content governance settings: default Guardrail set and provenance events.
 *
 * Both settings default to off/empty: without them the provider behaves
 * exactly as before. Policy (what to do with provenance, disclosure,
 * exemptions) lives in ECA/Workflow — see docs/content-governance.md.
 */
class GovernanceSettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'ai_provider_universal_governance.settings';

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_provider_universal_governance_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $set_options = [];
    foreach ($this->entityTypeManager->getStorage('ai_guardrail_set')->loadMultiple() as $set) {
      $set_options[$set->id()] = $set->label();
    }

    $form['default_guardrail_set'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Guardrail set'),
      '#options' => $set_options,
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $config->get('default_guardrail_set') ?: '',
      '#description' => $this->t('Applied to calls served by this provider when the caller attached no Guardrail set of its own. Configure the sets themselves (PII, topics, injection, …) in the <a href=":url">AI Guardrails UI</a>. A set on a smart route overrides this default.', [
        ':url' => Url::fromUserInput('/admin/config/ai/guardrails')->toString(),
      ]),
    ];

    $form['emit_provenance'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Emit content provenance events'),
      '#default_value' => (bool) $config->get('emit_provenance'),
      '#description' => $this->t('Dispatch an AI-origin fact event after each successful generation (model, server, operation, user — no prompt/response text). ECA, Workflow or custom subscribers implement site policy such as AI Act Art. 50 disclosure. Emitting the event alone changes nothing.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('default_guardrail_set', (string) $form_state->getValue('default_guardrail_set'))
      ->set('emit_provenance', (bool) $form_state->getValue('emit_provenance'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
