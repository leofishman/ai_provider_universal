<?php

namespace Drupal\ai_provider_universal_factcheck\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
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
   * The HTTP client, for fetching URLs to scan.
   */
  protected ClientInterface $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->httpClient = $container->get('http_client');
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

    $raw = $url ? $this->fetchUrl($url) : (string) $form_state->getValue('text');
    if ($raw === NULL) {
      // fetchUrl() already reported the error.
      return;
    }
    // Drop non-content markup before stripping tags, so menus/scripts don't
    // pollute the scanned text.
    $raw = preg_replace('/<(script|style|nav|header|footer)\b[^>]*>.*?<\/\1>/is', ' ', $raw);
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
    if (mb_strlen($text) < 10) {
      $this->messenger()->addWarning($this->t('No usable text found — nothing to scan.'));
      return;
    }

    $this->startScanBatch((string) $subject, $text, [
      'subject' => (string) $subject,
      'url' => $url ?: NULL,
    ], 'scan_standalone');
  }

  /**
   * Fetches a URL, NULL on failure (with a message shown to the user).
   */
  protected function fetchUrl(string $url): ?string {
    try {
      $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
      return (string) $response->getBody();
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not fetch %url: @message', [
        '%url' => $url,
        '@message' => $e->getMessage(),
      ]));
      return NULL;
    }
  }

}
