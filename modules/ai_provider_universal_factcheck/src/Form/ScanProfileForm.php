<?php

namespace Drupal\ai_provider_universal_factcheck\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for adding and editing aip_scan_profile entities.
 */
class ScanProfileForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ai_provider_universal_factcheck\Entity\ScanProfileInterface $profile */
    $profile = $this->entity;
    $checks = $profile->getChecks();

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Profile name'),
      '#maxlength' => 255,
      '#default_value' => $profile->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $profile->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_provider_universal_factcheck\Entity\ScanProfile::load',
      ],
      '#disabled' => !$profile->isNew(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $profile->status(),
    ];

    $bundle_options = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      $bundle_options[$type->id()] = $type->label();
    }
    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#options' => $bundle_options,
      '#default_value' => $profile->getBundles(),
      '#required' => TRUE,
      '#description' => $this->t('Nodes of these types are enqueued for review on save. Saving is never blocked; checks run later on cron.'),
    ];
    $form['operations'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Operations'),
      '#options' => [
        'insert' => $this->t('When created'),
        'update' => $this->t('When updated (only if the scannable text changed)'),
      ],
      '#default_value' => $profile->getOperations(),
      '#required' => TRUE,
    ];
    $form['published_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published nodes only'),
      '#default_value' => $profile->isPublishedOnly(),
      '#description' => $this->t('Skip drafts — the cheapest way to avoid spending LLM/API budget on work in progress.'),
    ];
    $form['cooldown'] = [
      '#type' => 'number',
      '#title' => $this->t('Cooldown (seconds)'),
      '#min' => 0,
      '#default_value' => $profile->getCooldown(),
      '#description' => $this->t('Minimum time before the same node is enqueued again by this profile.'),
    ];

    $form['checks'] = [
      '#type' => 'details',
      '#title' => $this->t('Checks'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('Readability is free and local. AI likelihood costs one LLM call; fact check and plagiarism are the heaviest — prefer light profiles for frequent saves.'),
    ];
    $form['checks']['readability'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Readability (free, local)'),
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enabled'),
        '#default_value' => !empty($checks['readability']['enabled']),
      ],
      'alert_below' => [
        '#type' => 'number',
        '#title' => $this->t('Alert below (Flesch reading ease)'),
        '#min' => 0,
        '#max' => 100,
        '#default_value' => $checks['readability']['alert_below'] ?? 30,
      ],
    ];
    $form['checks']['ai_likelihood'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('AI likelihood (one LLM call)'),
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enabled'),
        '#default_value' => !empty($checks['ai_likelihood']['enabled']),
      ],
      'alert_threshold' => [
        '#type' => 'number',
        '#title' => $this->t('Alert at or above (%)'),
        '#min' => 0,
        '#max' => 100,
        '#default_value' => $checks['ai_likelihood']['alert_threshold'] ?? 70,
        '#description' => $this->t('A detector score is a review hint, never proof of AI origin.'),
      ],
    ];
    $form['checks']['factcheck'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Fact check (heavy: claim extraction + verification)'),
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enabled'),
        '#default_value' => !empty($checks['factcheck']['enabled']),
      ],
      'alert_below' => [
        '#type' => 'number',
        '#title' => $this->t('Alert below (support score 0–1)'),
        '#min' => 0,
        '#max' => 1,
        '#step' => 0.05,
        '#default_value' => $checks['factcheck']['alert_below'] ?? 0.5,
      ],
    ];
    $form['checks']['plagiarism'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Plagiarism (external search API)'),
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enabled'),
        '#default_value' => !empty($checks['plagiarism']['enabled']),
      ],
      'alert_min_hits' => [
        '#type' => 'number',
        '#title' => $this->t('Alert at or above (matches)'),
        '#min' => 1,
        '#default_value' => $checks['plagiarism']['alert_min_hits'] ?? 1,
      ],
    ];

    $form['event_on'] = [
      '#type' => 'radios',
      '#title' => $this->t('Dispatch the content review event'),
      '#options' => [
        'threshold' => $this->t('Only when a threshold is crossed (recommended)'),
        'always' => $this->t('After every scan'),
        'never' => $this->t('Never (results are still stored)'),
      ],
      '#default_value' => $profile->getEventOn(),
      '#description' => $this->t('ECA, Workflow or custom subscribers react to the event (moderation state, mail, …). The scan itself never changes the node.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $this->entity->set('bundles', array_values(array_filter($form_state->getValue('bundles', []))));
    $this->entity->set('operations', array_values(array_filter($form_state->getValue('operations', []))));

    $checks = [];
    foreach ($form_state->getValue('checks', []) as $check_id => $settings) {
      $checks[$check_id] = ['enabled' => (bool) $settings['enabled']];
      foreach ($settings as $key => $value) {
        if ($key !== 'enabled' && $value !== '') {
          $checks[$check_id][$key] = $key === 'alert_below' && $check_id === 'factcheck'
            ? (float) $value
            : (is_numeric($value) ? (int) $value : $value);
        }
      }
    }
    $this->entity->set('checks', $checks);

    $status = $this->entity->save();
    $this->messenger()->addStatus($this->t('Scan profile %label saved.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
