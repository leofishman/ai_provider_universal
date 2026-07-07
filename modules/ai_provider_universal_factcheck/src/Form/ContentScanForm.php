<?php

namespace Drupal\ai_provider_universal_factcheck\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
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
 * The scan runs through the Batch API (one step per check) so slow model
 * calls get a progress bar and their own request time budget instead of one
 * long synchronous submit. Results land in the private tempstore and render
 * when the batch redirects back here.
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
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The private tempstore factory.
   */
  protected PrivateTempStoreFactory $tempStoreFactory;

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
    $instance->currentUser = $container->get('current_user');
    $instance->tempStoreFactory = $container->get('tempstore.private');
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

    // Results from the last batch run for this node, if any.
    $results = $this->tempStoreFactory->get('ai_provider_universal_factcheck')->get('scan_' . $node->id());
    if ($results) {
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

    // Collect clean text from body-like fields only. The title goes in as
    // context for the extractor, not as scannable text — otherwise it shows
    // up as a bogus "claim" of its own.
    $parts = [];
    foreach ($node->getFields() as $field) {
      $type = $field->getFieldDefinition()->getType();
      if (in_array($type, ['text', 'text_long', 'text_with_summary', 'string_long'], TRUE)) {
        foreach ($field as $item) {
          if (!empty($item->value)) {
            $parts[] = strip_tags((string) $item->value);
          }
          if (!empty($item->summary)) {
            $parts[] = strip_tags((string) $item->summary);
          }
        }
      }
    }
    $text = trim(preg_replace('/\s+/', ' ', implode('. ', array_filter($parts))));
    if (mb_strlen($text) < 10) {
      $this->messenger()->addWarning($this->t('The rendered content is empty — nothing to scan.'));
      return;
    }

    $this->startScanBatch((string) $node->label(), $text, [
      'subject' => (string) $node->label(),
      'node' => $node->id(),
    ], 'scan_' . $node->id());
  }

  /**
   * Builds and queues the scan batch: one operation per configured check.
   *
   * @param string $subject
   *   Label for the scan (node title, URL, …); also the fact-check context.
   * @param string $text
   *   The plain text to scan.
   * @param array $meta
   *   Extra fields for the persisted aip_factcheck_result row.
   * @param string $storeKey
   *   Private tempstore key the results are stored under for display.
   */
  protected function startScanBatch(string $subject, string $text, array $meta, string $storeKey): void {
    $builder = (new BatchBuilder())
      ->setTitle($this->t('Scanning %title', ['%title' => $subject]))
      ->setInitMessage($this->t('Starting content scan…'))
      ->setProgressMessage($this->t('Ran @current of @total checks.'))
      ->setErrorMessage($this->t('The content scan failed.'))
      ->setFinishCallback([static::class, 'batchFinished']);

    if ($this->factChecker->isConfigured()) {
      $builder->addOperation([static::class, 'batchExtractClaims'], [$subject, $text]);
      $builder->addOperation([static::class, 'batchVerifyClaims'], []);
    }
    $builder->addOperation([static::class, 'batchReadability'], [$text]);
    if ($this->aiDetector->isConfigured()) {
      $builder->addOperation([static::class, 'batchAiDetect'], [$text]);
    }
    if ($this->plagiarismChecker->isConfigured()) {
      $builder->addOperation([static::class, 'batchPlagiarism'], [$text]);
    }

    // Carry scan metadata to the finished callback via the results bucket.
    $builder->addOperation([static::class, 'batchMeta'], [
      $meta + [
        'uid' => $this->currentUser->id(),
        'scanned' => $text,
        'store_key' => $storeKey,
      ],
    ]);

    batch_set($builder->toArray());
  }

  /**
   * Batch op: extract factual claims from the scanned text.
   */
  public static function batchExtractClaims(string $question, string $text, array &$context): void {
    /** @var \Drupal\ai_provider_universal_factcheck\Service\FactChecker $checker */
    $checker = \Drupal::service(FactChecker::class);
    $context['results']['claims'] = $checker->extractClaims($text, $question);
    $context['message'] = t('Extracted @count factual claims.', ['@count' => count($context['results']['claims'])]);
  }

  /**
   * Batch op: verify the previously extracted claims.
   */
  public static function batchVerifyClaims(array &$context): void {
    $claims = $context['results']['claims'] ?? [];
    if (!$claims) {
      $context['results']['factcheck'] = ['score' => 1.0, 'claims' => []];
      return;
    }
    // Multi-pass: verify a few claims per batch request so no single HTTP
    // request outlives reverse-proxy read timeouts (Cloudflare kills the
    // connection at ~100s with a 524). Per-claim verdicts are cached, so
    // re-runs stay cheap.
    $sandbox = &$context['sandbox'];
    $sandbox['pos'] ??= 0;
    $sandbox['records'] ??= [];
    /** @var \Drupal\ai_provider_universal_factcheck\Service\FactChecker $checker */
    $checker = \Drupal::service(FactChecker::class);
    $chunk = array_slice($claims, $sandbox['pos'], 3);
    $result = $checker->verifyClaims($chunk);
    $sandbox['records'] = array_merge($sandbox['records'], $result['claims']);
    $sandbox['pos'] += count($chunk);
    if ($sandbox['pos'] < count($claims)) {
      $context['finished'] = $sandbox['pos'] / count($claims);
      $context['message'] = t('Verified @done of @total claims…', [
        '@done' => $sandbox['pos'],
        '@total' => count($claims),
      ]);
      return;
    }
    $records = $sandbox['records'];
    // Same scoring as FactChecker::verifyClaims(), over the merged records.
    $supported = count(array_filter($records, static fn (array $r): bool => $r['verdict'] === 'SUPPORTED'));
    $tainted = count(array_filter($records, static fn (array $r): bool => $r['tainted']));
    $context['results']['factcheck'] = [
      'score' => max(0.0, ($supported - 0.5 * $tainted) / count($claims)),
      'claims' => $records,
    ];
    $context['message'] = t('Verified claims against the evidence.');
  }

  /**
   * Batch op: readability score (local, fast).
   */
  public static function batchReadability(string $text, array &$context): void {
    $context['results']['readability'] = \Drupal::service(ReadabilityScorer::class)->score($text);
  }

  /**
   * Batch op: AI-likelihood heuristic.
   */
  public static function batchAiDetect(string $text, array &$context): void {
    $context['results']['ai'] = \Drupal::service(AiDetector::class)->detect($text);
    $context['message'] = t('Estimated AI likelihood.');
  }

  /**
   * Batch op: verbatim plagiarism search.
   */
  public static function batchPlagiarism(string $text, array &$context): void {
    $context['results']['plagiarism'] = \Drupal::service(PlagiarismChecker::class)->check($text);
    $context['message'] = t('Searched the web for verbatim copies.');
  }

  /**
   * Batch op: stash scan metadata for the finished callback.
   */
  public static function batchMeta(array $meta, array &$context): void {
    $context['results']['meta'] = $meta;
  }

  /**
   * Batch finished: store results for display and persist the result row.
   */
  public static function batchFinished(bool $success, array $batchResults, array $operations): void {
    if (!$success) {
      \Drupal::messenger()->addError(t('The content scan failed. Check the site log for details.'));
      return;
    }
    $meta = $batchResults['meta'] ?? [];
    $results = [
      'factcheck' => $batchResults['factcheck'] ?? NULL,
      'readability' => $batchResults['readability'] ?? NULL,
      'ai' => $batchResults['ai'] ?? NULL,
      'plagiarism' => $batchResults['plagiarism'] ?? NULL,
      '_scanned' => $meta['scanned'] ?? '',
    ];

    \Drupal::service('tempstore.private')
      ->get('ai_provider_universal_factcheck')
      ->set($meta['store_key'] ?? 'scan_0', $results);

    // Persist an aip_factcheck_result row (best effort): the rows feed the
    // shipped "Fact check results" view; failing to write one must never
    // fail the scan itself.
    try {
      $fields = array_diff_key($meta, array_flip(['scanned', 'store_key']));
      \Drupal::entityTypeManager()->getStorage('aip_factcheck_result')->create($fields + [
        'score' => $results['factcheck']['score'] ?? NULL,
        'ai_score' => $results['ai']['score'] ?? NULL,
        'readability' => $results['readability']['score'] ?? NULL,
        'plagiarism_matches' => $results['plagiarism'] === NULL ? NULL : count($results['plagiarism']),
        'details' => $results,
      ])->save();
    }
    catch (\Throwable $e) {
      \Drupal::logger('ai_provider_universal_factcheck')->warning('Could not store the scan result: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * One-line, Ground-News-style description of a claim's evidence coverage.
   *
   * "3 sources, 2 independent — ⚠ only left-leaning coverage": how broad
   * the evidence base really is once wire-copy siblings are collapsed, and
   * whether it comes from one side of the spectrum only.
   */
  protected function formatCoverage(array $coverage): string {
    if (!$coverage) {
      return '';
    }
    $text = (string) $this->t('@sources sources, @independent independent', [
      '@sources' => $coverage['sources'],
      '@independent' => $coverage['independent'],
    ]);
    if (!empty($coverage['blindspot'])) {
      $text .= ' — ⚠ ' . $this->t('only @side-leaning coverage', ['@side' => $coverage['blindspot']]);
    }
    return $text;
  }

  /**
   * Renders the scan results.
   */
  protected function buildResults(array $results): array {
    $build = ['#type' => 'container'];

    // What was actually scanned, so reviewers keep the content in view while
    // reading the verdicts.
    if (!empty($results['_scanned'])) {
      $build['scanned'] = [
        '#type' => 'details',
        '#title' => $this->t('Scanned text (@count characters)', ['@count' => mb_strlen($results['_scanned'])]),
        '#open' => FALSE,
        'text' => ['#plain_text' => $results['_scanned']],
      ];
    }

    if ($fc = $results['factcheck']) {
      $excerpt = $this->t('Scanned excerpt: @excerpt', ['@excerpt' => mb_substr($results['_scanned'] ?? '', 0, 200) . '…']);
      $emptyText = $this->t('No factual claims found — nothing to verify.') . '<br><small>' . $excerpt . '</small>';
      $rows = array_map(function (array $c) {
        $verdict = $c['verdict'];
        $icon = match ($verdict) {
          'SUPPORTED' => '✅',
          'CONTRADICTED' => '❌',
          default => '⚠️',
        };
        $verdictText = $icon . ' ' . $verdict;
        if (!empty($c['tainted'])) {
          $verdictText = (string) $this->t('@verdict — ⚠ echoed by distrusted sites', ['@verdict' => $verdictText]);
        }
        // Long discrepancy analyses collapse behind a summary so the table
        // stays readable.
        $analysis = trim((string) ($c['analysis'] ?? ''));
        $analysisCell = mb_strlen($analysis) > 160
          ? [
            'data' => [
              '#type' => 'details',
              '#title' => $this->t('Analysis'),
              '#open' => FALSE,
              'text' => ['#plain_text' => $analysis],
            ],
          ]
          : $analysis;
        return [
          ['data' => ['#markup' => '<strong>' . htmlspecialchars($c['claim']) . '</strong>']],
          $verdictText,
          $this->formatCoverage($c['coverage'] ?? []),
          $analysisCell,
        ];
      }, $fc['claims']);
      $build['factcheck'] = [
        '#type' => 'details',
        '#title' => $this->t('Fact check — support score @score%', ['@score' => (int) round($fc['score'] * 100)]),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Claim'), $this->t('Verdict'), $this->t('Coverage'), $this->t('Discrepancy analysis')],
          '#rows' => $rows,
          '#empty' => ['#markup' => $emptyText],
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
