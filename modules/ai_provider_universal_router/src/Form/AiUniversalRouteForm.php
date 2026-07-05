<?php

namespace Drupal\ai_provider_universal_router\Form;

use Drupal\ai_provider_universal\Entity\AiUniversalModelInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for adding and editing ai_universal_route entities.
 */
class AiUniversalRouteForm extends EntityForm {

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
    /** @var \Drupal\ai_provider_universal_router\Entity\AiUniversalRouteInterface $route */
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
        'exists' => '\Drupal\ai_provider_universal_router\Entity\AiUniversalRoute::load',
      ],
      '#disabled' => !$route->isNew(),
    ];

    // AJAX: rebuild candidates when the operation type changes.
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
      '#ajax' => [
        'callback' => '::updateCandidates',
        'wrapper' => 'route-candidates-wrapper',
      ],
    ];

    // Use form_state value during AJAX, entity value on initial load.
    $selectedOp = $form_state->getValue('operation_type') ?? $route->getOperationType();

    $form['candidates_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'route-candidates-wrapper'],
    ];
    $form['candidates_wrapper']['candidates'] = $this->buildCandidates($selectedOp, $route->getCandidates());

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
   * Builds the candidate models checkboxes filtered by operation type.
   *
   * Each option label includes cost, quality tier, and effective operation
   * types. When a model has manual overrides the detected types are shown
   * for reference.
   *
   * @param string $operationType
   *   The operation type to filter by.
   * @param string[] $defaultCandidates
   *   Currently selected candidate model ids (from the entity).
   *
   * @return array
   *   A FAPI checkboxes element.
   */
  protected function buildCandidates(string $operationType, array $defaultCandidates): array {
    $model_storage = $this->entityTypeManager->getStorage('ai_universal_model');
    $options = [];

    /** @var \Drupal\ai_provider_universal\Entity\AiUniversalModelInterface $model */
    foreach ($model_storage->loadMultiple() as $model) {
      $effective = $model->getEffectiveOperationTypes();
      if (!in_array($operationType, $effective, TRUE)) {
        continue;
      }

      $options[$model->id()] = $this->buildModelLabel($model);
    }

    return [
      '#type' => 'checkboxes',
      '#title' => $this->t('Candidate models'),
      '#description' => $options
        ? $this->t('Only models supporting %type are shown. Leave all unchecked to consider every model that supports the operation type. The router picks the cheapest candidate whose quality tier satisfies the prompt class.', ['%type' => $operationType])
        : $this->t('No models support the %type operation type. Run model discovery on a server or edit model capabilities.', ['%type' => $operationType]),
      '#options' => $options,
      '#default_value' => $defaultCandidates,
      // Keep the value path flat so save() can read it as 'candidates'.
      '#parents' => ['candidates'],
    ];
  }

  /**
   * Builds a descriptive label for a model checkbox option.
   *
   * Shows tier, cost, and operation type provenance (detected vs overridden).
   */
  protected function buildModelLabel(AiUniversalModelInterface $model): string {
    $cost = $model->getCostInput();
    $tier = $model->getQualityTier();

    $overrides = $model->getOperationTypes();
    $effective = $model->getEffectiveOperationTypes();
    $typesLabel = implode(', ', $effective);

    if ($overrides) {
      $detected = $model->getDetectedOperationTypes() ?: [];
      $typesLabel .= ' ⚙ ' . $this->t('(detected: @detected)', [
        '@detected' => $detected ? implode(', ', $detected) : $this->t('none'),
      ]);
    }

    return $this->t('@label (tier @tier, $@cost/1M in) — @types', [
      '@label' => $model->label(),
      '@tier' => $tier ?? '?',
      '@cost' => $cost ?? '?',
      '@types' => $typesLabel,
    ]);
  }

  /**
   * AJAX callback: returns the rebuilt candidates wrapper.
   */
  public static function updateCandidates(array &$form, FormStateInterface $form_state): array {
    return $form['candidates_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    // An emptied number element submits '', which PHP cannot coerce onto the
    // typed float property: drop it so the entity keeps its current value.
    if ($form_state->getValue('factcheck_min_score') === '') {
      $form_state->unsetValue('factcheck_min_score');
    }
    parent::copyFormValuesToEntity($entity, $form, $form_state);
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
