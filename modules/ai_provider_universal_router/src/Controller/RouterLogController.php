<?php

namespace Drupal\ai_provider_universal_router\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Savings dashboard: recent routing decisions and cumulative estimates.
 */
class RouterLogController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the dashboard.
   */
  public function report(): array {
    $totals = $this->database->query(
      'SELECT COUNT(*) AS decisions, SUM(est_cost) AS spent, SUM(est_cost_worst - est_cost) AS saved FROM {ai_universal_router_log}'
    )->fetchAssoc() ?: ['decisions' => 0, 'spent' => 0, 'saved' => 0];

    $build['summary'] = [
      '#markup' => '<p>' . $this->t(
        '<strong>@count</strong> routed requests — estimated spend <strong>$@spent</strong>, estimated savings vs. always using the most expensive candidate: <strong>$@saved</strong>.',
        [
          '@count' => (int) $totals['decisions'],
          '@spent' => number_format((float) $totals['spent'], 4),
          '@saved' => number_format((float) $totals['saved'], 4),
        ]
      ) . '</p>',
    ];

    $rows = [];
    $result = $this->database->select('ai_universal_router_log', 'l')
      ->fields('l')
      ->orderBy('id', 'DESC')
      ->range(0, 100)
      ->execute();

    foreach ($result as $record) {
      $rows[] = [
        $this->dateFormatter->format($record->timestamp, 'short'),
        $record->route_id,
        $record->complexity,
        $record->est_tokens,
        $record->chosen_model,
        '$' . number_format($record->est_cost, 6),
        '$' . number_format($record->est_cost_worst - $record->est_cost, 6),
      ];
    }

    $build['log'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('When'),
        $this->t('Route'),
        $this->t('Complexity'),
        $this->t('Est. tokens'),
        $this->t('Chosen model'),
        $this->t('Est. cost'),
        $this->t('Saved'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No routing decisions yet. Select a smart route as model in the AI settings and run a request.'),
    ];

    return $build;
  }

}
