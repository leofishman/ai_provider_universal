<?php

namespace Drupal\ai_provider_universal_factcheck\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\ai_provider_universal_factcheck\Exception\PageFetchException;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Standalone content scan: paste text or point at any URL.
 *
 * Same checks and result rendering as the per-node Content scan tab
 * (extends it), but the subject comes from a textarea or a fetched page —
 * internal or external — instead of a node. Nothing is stored.
 */
class StandaloneFactCheckForm extends ContentScanForm {

  /**
   * Fetches URLs to scan.
   */
  protected PageFetcher $pageFetcher;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->pageFetcher = $container->get(PageFetcher::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_provider_universal_factcheck_standalone';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL'),
      '#description' => $this->t('Any page, on this site or elsewhere. Its text content is extracted and scanned.'),
    ];
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Or paste text'),
      '#rows' => 8,
      '#description' => $this->t('Used when no URL is given.'),
    ];
    $form['scan'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run scan'),
    ];

    $results = $this->tempStoreFactory->get('ai_provider_universal_factcheck')->get('scan_standalone');
    if ($results) {
      $form['results'] = $this->buildResults($results);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if (trim((string) $form_state->getValue('url')) === '' && trim((string) $form_state->getValue('text')) === '') {
      $form_state->setErrorByName('url', $this->t('Provide a URL or paste some text.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $url = trim((string) $form_state->getValue('url'));
    $subject = $url ?: $this->t('Pasted text');

    try {
      $text = $url
        ? $this->pageFetcher->fetchText($url)
        : $this->pageFetcher->toText((string) $form_state->getValue('text'));
    }
    catch (PageFetchException $e) {
      $this->messenger()->addError($e->userMessage);
      return;
    }
    if (mb_strlen($text) < 10) {
      $this->messenger()->addWarning($this->t('No usable text found — nothing to scan.'));
      return;
    }

    if (!$this->checkScanFlood()) {
      return;
    }

    $this->startScanBatch((string) $subject, $text, [
      'subject' => (string) $subject,
      'url' => $url ?: NULL,
    ], 'scan_standalone');
  }

}
