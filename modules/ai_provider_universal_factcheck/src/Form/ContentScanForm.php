<?php

namespace Drupal\ai_provider_universal_factcheck\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\ai_provider_universal_factcheck\Service\AiDetector;
use Drupal\ai_provider_universal_factcheck\Service\FactChecker;
use Drupal\ai_provider_universal_factcheck\Service\PlagiarismChecker;
use Drupal\ai_provider_universal_factcheck\Service\ReadabilityScorer;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Per-node content scan: fact check, readability, AI likelihood, plagiarism.
 *
 * Results are computed on demand and shown on the page only — no storage.
 * Each section renders only when its service is configured.
 */
class ContentScanForm extends FormBase {

  /**
   * The fact checker.
   */
  protected FactChecker $factChecker;

  /**
   * The readability scorer.
   */
  protected ReadabilityScorer $readabilityScorer;

  /**
   * The AI-likelihood detector.
   */
  protected AiDetector $aiDetector;

  /**
   * The plagiarism checker.
   */
  protected PlagiarismChecker $plagiarismChecker;

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->factChecker = $container->get(FactChecker::class);
    $instance->readabilityScorer = $container->get(ReadabilityScorer::class);
    $instance->aiDetector = $container->get(AiDetector::class);
    $instance->plagiarismChecker = $container->get(PlagiarismChecker::class);
    $instance->renderer = $container->get('renderer');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_provider_universal_factcheck_content_scan';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    $form_state->set('node', $node);

    $checks = array_filter([
      $this->factChecker->isConfigured() ? $this->t('fact check') : NULL,
      $this->t('readability'),
      $this->aiDetector->isConfigured() ? $this->t('AI detection') : NULL,
      $this->plagiarismChecker->isConfigured() ? $this->t('plagiarism') : NULL,
    ]);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Scans %title with: @checks. Model-based checks cost tokens and can take a while.', [
        '%title' => $node->label(),
        '@checks' => implode(', ', $checks),
      ]) . '</p>',
    ];
    if (!$this->factChecker->isConfigured()) {
      $form['intro']['#markup'] .= '<p>' . $this->t('Fact checking is disabled: configure a checker model in the <a href=":url">fact check settings</a>.', [
        ':url' => Url::fromRoute('ai_provider_universal_factcheck.settings')->toString(),
      ]) . '</p>';
    }

    $form['scan'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run scan'),
    ];

    if ($results = $form_state->get('results')) {
      $form['results'] = $this->buildResults($results);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form_state->get('node');
    $view = $this->entityTypeManager->getViewBuilder('node')->view($node);
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->renderer->renderInIsolation($view))));
    if (mb_strlen($text) < 10) {
      $this->messenger()->addWarning($this->t('The rendered content is empty — nothing to scan.'));
      return;
    }

    $results = [
      'factcheck' => $this->factChecker->isConfigured()
        ? $this->factChecker->verify((string) $node->label(), $text)
        : NULL,
      'readability' => $this->readabilityScorer->score($text),
      'ai' => $this->aiDetector->detect($text),
      'plagiarism' => $this->plagiarismChecker->check($text),
    ];
    $form_state->set('results', $results);
    $form_state->setRebuild();
  }

  /**
   * Renders the scan results.
   */
  protected function buildResults(array $results): array {
    $build = ['#type' => 'container'];

    if ($fc = $results['factcheck']) {
      $rows = array_map(fn (array $c) => [
        $c['claim'],
        !empty($c['tainted'])
          ? $this->t('@verdict — ⚠ echoed by distrusted sites', ['@verdict' => $c['verdict']])
          : $c['verdict'],
      ], $fc['claims']);
      $build['factcheck'] = [
        '#type' => 'details',
        '#title' => $this->t('Fact check — support score @score%', ['@score' => (int) round($fc['score'] * 100)]),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Claim'), $this->t('Verdict')],
          '#rows' => $rows,
          '#empty' => $this->t('No factual claims found — nothing to verify.'),
        ],
      ];
    }

    if ($read = $results['readability']) {
      $build['readability'] = [
        '#type' => 'details',
        '#title' => $this->t('Readability — @score (@band)', ['@score' => $read['score'], '@band' => $read['band']]),
        '#open' => TRUE,
        'detail' => [
          '#markup' => $this->t('Flesch reading ease over @words words in @sentences sentences. Higher is easier to read.', [
            '@words' => $read['words'],
            '@sentences' => $read['sentences'],
          ]),
        ],
      ];
    }

    if ($ai = $results['ai']) {
      $build['ai'] = [
        '#type' => 'details',
        '#title' => $this->t('AI likelihood — @score%', ['@score' => $ai['score']]),
        '#open' => TRUE,
        'detail' => [
          '#markup' => $this->t('@rationale (Heuristic LLM judgement, not a trained detector — treat as a hint.)', [
            '@rationale' => $ai['rationale'],
          ]),
        ],
      ];
    }

    if (($plag = $results['plagiarism']) !== NULL) {
      $rows = [];
      foreach ($plag as $match) {
        $rows[] = [
          $match['sentence'],
          [
            'data' => [
              '#type' => 'link',
              '#title' => $match['title'] ?: $match['url'],
              '#url' => Url::fromUri($match['url']),
            ],
          ],
          $match['snippet'],
        ];
      }
      $build['plagiarism'] = [
        '#type' => 'details',
        '#title' => $this->t('Plagiarism — @count verbatim matches', ['@count' => count($plag)]),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Sentence'), $this->t('Found at'), $this->t('Snippet')],
          '#rows' => $rows,
          '#empty' => $this->t('No verbatim copies of this content found on the web.'),
        ],
      ];
    }

    return $build;
  }

}
