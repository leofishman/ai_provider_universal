<?php

namespace Drupal\ai_provider_universal_router\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for adding and editing universal_route entities.
 */
class UniversalRouteForm extends EntityForm {

  /**
   * Tier options shared by the two threshold selects.
   */
  protected function tierOptions(): array {
    return [
      1 => $this->t('1 — Minimal'),
      2 => $this->t('2 — Basic'),
      3 => $this->t('3 — Solid'),
      4 => $this->t('4 — Strong'),
      5 => $this->t('5 — Frontier'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ai_provider_universal_router\Entity\UniversalRouteInterface $route */
    $route = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Route name'),
      '#description' => $this->t('Shown in the AI model dropdown, e.g. "Auto: cheapest capable".'),
      '#maxlength' => 255,
      '#default_value' => $route->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $route->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_provider_universal_router\Entity\UniversalRoute::load',
      ],
      '#disabled' => !$route->isNew(),
    ];

    $form['operation_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Operation type'),
      '#options' => [
        'chat' => $this->t('Chat'),
        'embeddings' => $this->t('Embeddings'),
        'moderation' => $this->t('Moderation'),
        'rerank' => $this->t('Rerank'),
      ],
      '#default_value' => $route->getOperationType(),
    ];

    $model_storage = $this->entityTypeManager->getStorage('universal_model');
    $options = [];
    /** @var \Drupal\ai_provider_universal\Entity\UniversalModelInterface $model */
    foreach ($model_storage->loadMultiple() as $model) {
      $cost = $model->getCostInput();
      $tier = $model->getQualityTier();
      $options[$model->id()] = $this->t('@label (tier @tier, $@cost/1M in)', [
        '@label' => $model->label(),
        '@tier' => $tier ?? '?',
        '@cost' => $cost ?? '?',
      ]);
    }

    $form['candidates'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Candidate models'),
      '#description' => $this->t('Leave all unchecked to consider every model that supports the operation type. The router picks the cheapest candidate whose quality tier satisfies the prompt class.'),
      '#options' => $options,
      '#default_value' => $route->getCandidates(),
    ];

    $form['simple_tier'] = [
      '#type' => 'select',
      '#title' => $this->t('Minimum tier for simple prompts'),
      '#options' => $this->tierOptions(),
      '#default_value' => $route->getSimpleTier(),
    ];

    $form['complex_tier'] = [
      '#type' => 'select',
      '#title' => $this->t('Minimum tier for complex prompts'),
      '#description' => $this->t('Prompts are classified complex when long or containing code/reasoning cues; those require at least this tier.'),
      '#options' => $this->tierOptions(),
      '#default_value' => $route->getComplexTier(),
    ];

    if ($this->moduleHandler->moduleExists('ai_provider_universal_factcheck')) {
      $form['factcheck'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Fact-check answers and escalate on failure'),
        '#description' => $this->t('Chat answers are verified claim by claim (see the Fact Check settings for checker model and evidence index). Below the score threshold the request is retried with the best candidate.'),
        '#default_value' => $route->isFactcheckEnabled(),
      ];
      $form['factcheck_min_score'] = [
        '#type' => 'number',
        '#title' => $this->t('Minimum support score'),
        '#description' => $this->t('Fraction of claims that must be SUPPORTED (0–1).'),
        '#default_value' => $route->getFactcheckMinScore(),
        '#min' => 0,
        '#max' => 1,
        '#step' => 0.05,
        '#states' => [
          'visible' => [':input[name="factcheck"]' => ['checked' => TRUE]],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $this->entity->set('candidates', array_values(array_filter($form_state->getValue('candidates', []))));
    $status = $this->entity->save();

    $this->messenger()->addStatus($this->t('Smart route %label saved. It appears as a model option for %type operations.', [
      '%label' => $this->entity->label(),
      '%type' => $this->entity->getOperationType(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
