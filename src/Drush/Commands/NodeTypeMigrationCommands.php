<?php

namespace Drupal\operations_cider\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\operations_cider\NodeTypeMigration;
use Drupal\operations_cider\QueueSpecMigrator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Read-only Drush commands that help write and review the D8-3090 migration.
 *
 * None of these commands changes the site: they preview what the deploy hook
 * will do, draft entries for migration/d3090_curated.yml, and render that file
 * for review.
 */
final class NodeTypeMigrationCommands extends DrushCommands {

  /**
   * Labels of the accelerator types, for the summary.
   */
  private const ACCELERATOR_LABELS = [
    'gpu' => 'GPU',
    'fpga' => 'FPGA',
    'vector_engine' => 'Vector engine',
    'other' => 'Other',
  ];

  /**
   * Labels of the "charged per" values, for the summary.
   */
  private const CHARGED_PER_LABELS = [
    'core' => 'Core',
    'gpu' => 'GPU',
    'node' => 'Whole node',
  ];

  /**
   * Labels of the queue access values, for the summary.
   */
  private const ACCESS_LABELS = [
    'batch' => 'Batch',
    'interactive' => 'Interactive only',
    'by_request' => 'By request',
  ];

  public function __construct(
    private readonly NodeTypeMigration $migration,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('operations_cider.node_type_migration'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Shows what the D8-3090 deploy hook would do to each resource.
   *
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   One row per candidate resource.
   */
  #[CLI\Command(name: 'operations_cider:d3090-plan', aliases: ['oc-d3090-plan'])]
  #[CLI\Usage(name: 'operations_cider:d3090-plan', description: 'List every candidate resource with its action and reasons.')]
  #[CLI\FieldLabels(labels: [
    'nid' => 'Nid',
    'title' => 'Title',
    'global_id' => 'Global resource id',
    'action' => 'Action',
    'reasons' => 'Reasons',
  ])]
  #[CLI\DefaultTableFields(fields: ['nid', 'title', 'global_id', 'action', 'reasons'])]
  public function plan(array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($this->migration->candidateIds());
    foreach ($nodes as $node) {
      $plan = $this->migration->plan($node);
      $rows[] = [
        'nid' => $node->id(),
        'title' => $node->label(),
        'global_id' => $plan['global_id'] ?? '',
        'action' => $plan['action'],
        'reasons' => implode(' ', $plan['reasons']),
      ];
    }
    $counts = array_count_values(array_column($rows, 'action'));
    $this->logger()->notice(dt('@total candidate resources: @curated curated, @automatic automatic, @skipped skipped.', [
      '@total' => count($rows),
      '@curated' => $counts['curated'] ?? 0,
      '@automatic' => $counts['automatic'] ?? 0,
      '@skipped' => $counts['skipped'] ?? 0,
    ]));
    return new RowsOfFields($rows);
  }

  /**
   * Prints a draft curated entry for a resource, to edit by hand.
   *
   * The draft is what the automatic migration would write, even when the
   * resource is flagged. The legacy rows and the flag reasons come first as
   * YAML comments, so the source is visible while editing.
   *
   * @param string $global_id
   *   The global resource id (field_access_global_resource_id).
   */
  #[CLI\Command(name: 'operations_cider:d3090-draft')]
  #[CLI\Argument(name: 'global_id', description: 'The global resource id.')]
  #[CLI\Usage(name: 'operations_cider:d3090-draft expanse.sdsc.xsede.org', description: 'Print a draft entry for that resource.')]
  public function draft(string $global_id): int {
    $node = $this->findResource($global_id);
    if (!$node) {
      $this->logger()->error(dt('No resource with global resource id "@id".', ['@id' => $global_id]));
      return self::EXIT_FAILURE;
    }
    if (!$node->get('field_rp_node_types')->isEmpty()) {
      $this->logger()->error(dt('"@title" already has node types; there is nothing to migrate.', ['@title' => $node->label()]));
      return self::EXIT_FAILURE;
    }
    $rows = $this->migration->legacyRows($node);
    if (!$rows) {
      $this->logger()->error(dt('"@title" has no legacy queue rows that load.', ['@title' => $node->label()]));
      return self::EXIT_FAILURE;
    }

    $migrator = new QueueSpecMigrator();
    $flags = $migrator->flags($rows);
    $result = $migrator->migrate($rows);

    $out = ["# Legacy rows of \"{$node->label()}\":"];
    foreach ($rows as $row) {
      $out[] = '#   ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $out[] = '# Flags:';
    foreach ($flags ?: ['none'] as $flag) {
      $out[] = '#   ' . $flag;
    }
    $out[] = '# Migrator report:';
    foreach ($result['report'] ?: ['none'] as $line) {
      $out[] = '#   ' . $line;
    }
    $entry = [
      $global_id => [
        'title' => (string) $node->label(),
        'legacy_hash' => QueueSpecMigrator::legacyHash($rows),
        'node_types' => $result['node_types'],
        'queues' => $result['queues'],
      ],
    ];
    $this->output()->writeln(implode("\n", $out));
    $this->output()->write(Yaml::dump($entry, 8, 2));
    return self::EXIT_SUCCESS;
  }

  /**
   * Renders the curated file as a per-resource summary for review.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'operations_cider:d3090-summary', aliases: ['oc-d3090-summary'])]
  #[CLI\Option(name: 'output', description: 'Write the Markdown to this file instead of stdout.')]
  #[CLI\Usage(name: 'operations_cider:d3090-summary --output=/tmp/d3090.md', description: 'Write the review summary to a file.')]
  public function summary(array $options = ['output' => NULL]): int {
    $markdown = $this->renderSummary($this->migration->curated());
    if (!empty($options['output'])) {
      if (file_put_contents($options['output'], $markdown) === FALSE) {
        $this->logger()->error(dt('Could not write @path.', ['@path' => $options['output']]));
        return self::EXIT_FAILURE;
      }
      $this->logger()->success(dt('Wrote @path.', ['@path' => $options['output']]));
      return self::EXIT_SUCCESS;
    }
    $this->output()->write($markdown);
    return self::EXIT_SUCCESS;
  }

  /**
   * Renders curated entries as Markdown.
   *
   * @param array<string, array<string, mixed>> $curated
   *   Entries keyed by global resource id.
   *
   * @return string
   *   The Markdown.
   */
  private function renderSummary(array $curated): string {
    if (!$curated) {
      return "No curated entries.\n";
    }
    $out = [];
    foreach ($curated as $globalId => $entry) {
      $out[] = sprintf('## %s (%s)', $entry['title'] ?? 'Untitled', $globalId);
      $out[] = '';
      if (!empty($entry['notes'])) {
        $out[] = '**Notes:** ' . trim((string) $entry['notes']);
        $out[] = '';
      }
      $out[] = '**Legacy rows:** ' . $this->hashStatus((string) $globalId, $entry);
      $out[] = '';
      $errors = $this->migration->validate([
        'node_types' => $entry['node_types'] ?? [],
        'queues' => $entry['queues'] ?? [],
      ]);
      if ($errors) {
        $out[] = '**Validation errors:**';
        foreach ($errors as $error) {
          $out[] = '- ' . $error;
        }
        $out[] = '';
      }
      $out = array_merge($out, $this->nodeTypeTable($entry), $this->queueTable($entry));
    }
    return implode("\n", $out) . "\n";
  }

  /**
   * Whether the entry's hash matches the site's current legacy rows.
   *
   * @param string $globalId
   *   The global resource id.
   * @param array<string, mixed> $entry
   *   A curated entry.
   */
  private function hashStatus(string $globalId, array $entry): string {
    $node = $this->findResource($globalId);
    if (!$node) {
      return 'resource not found on this site.';
    }
    if (!$node->get('field_rp_node_types')->isEmpty()) {
      return 'already migrated.';
    }
    $rows = $this->migration->legacyRows($node);
    if (!$rows) {
      return 'queue rows do not load.';
    }
    return QueueSpecMigrator::legacyHash($rows) === ($entry['legacy_hash'] ?? NULL)
      ? 'match the entry (hash matches).'
      : 'CHANGED since the entry was written (hash differs).';
  }

  /**
   * Markdown table of an entry's node types.
   *
   * @param array<string, mixed> $entry
   *   A curated entry.
   *
   * @return list<string>
   *   Lines.
   */
  private function nodeTypeTable(array $entry): array {
    $out = ['### Node types', ''];
    if (empty($entry['node_types'])) {
      return array_merge($out, ['None.', '']);
    }
    $out[] = '| Label | Nodes | CPU | Architecture | Cores | RAM | Accelerators | Queues |';
    $out[] = '|---|---|---|---|---|---|---|---|';
    foreach ($entry['node_types'] as $type) {
      $accelerators = [];
      foreach ($type['accelerators'] ?? [] as $accelerator) {
        $label = self::ACCELERATOR_LABELS[$accelerator['type'] ?? ''] ?? (string) ($accelerator['type'] ?? '?');
        $memory = isset($accelerator['memory']) ? ', ' . $accelerator['memory'] . ' GB' : '';
        $accelerators[] = sprintf('%s %s (%s%s)', isset($accelerator['count']) ? $accelerator['count'] . '×' : '?×', $accelerator['model'] ?? 'unknown', $label, $memory);
      }
      $queues = [];
      foreach ($type['queues'] ?? [] as $queue) {
        $queues[] = $queue['name'] . (isset($queue['node_count']) ? ' (' . $queue['node_count'] . ')' : '');
      }
      $out[] = '| ' . implode(' | ', array_map([$this, 'cell'], [
        $type['label'] ?? '',
        $type['node_count'] ?? '',
        $type['cpu_type'] ?? '',
        $type['cpu_architecture'] ?? '',
        $type['cpu_count'] ?? '',
        isset($type['ram']) ? $type['ram'] . ' GB' : '',
        implode('; ', $accelerators),
        implode('; ', $queues),
      ])) . ' |';
    }
    $out[] = '';
    return $out;
  }

  /**
   * Markdown table of an entry's queues.
   *
   * @param array<string, mixed> $entry
   *   A curated entry.
   *
   * @return list<string>
   *   Lines.
   */
  private function queueTable(array $entry): array {
    $out = ['### Queues', ''];
    if (empty($entry['queues'])) {
      return array_merge($out, ['None.', '']);
    }
    $served = [];
    foreach ($entry['node_types'] ?? [] as $type) {
      foreach ($type['queues'] ?? [] as $queue) {
        $served[$queue['name']][] = ($type['label'] ?? '') . (isset($queue['node_count']) ? ' (' . $queue['node_count'] . ')' : '');
      }
    }
    $out[] = '| Name | Purpose | Max wall time | Charged per | Access | Node types |';
    $out[] = '|---|---|---|---|---|---|';
    foreach ($entry['queues'] as $queue) {
      $out[] = '| ' . implode(' | ', array_map([$this, 'cell'], [
        $queue['name'] ?? '',
        $queue['purpose'] ?? '',
        $this->wallTime($queue['max_wall_minutes'] ?? NULL),
        self::CHARGED_PER_LABELS[$queue['charged_per'] ?? ''] ?? '',
        self::ACCESS_LABELS[$queue['access'] ?? ''] ?? '',
        implode('; ', $served[$queue['name'] ?? ''] ?? []),
      ])) . ' |';
    }
    $out[] = '';
    return $out;
  }

  /**
   * Formats minutes as hours and minutes.
   */
  private function wallTime(?int $minutes): string {
    if ($minutes === NULL) {
      return '';
    }
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    if ($hours === 0) {
      return $rest . ' min';
    }
    return $rest === 0 ? $hours . ' h' : sprintf('%d h %d min', $hours, $rest);
  }

  /**
   * Makes a value safe for a Markdown table cell.
   */
  private function cell(mixed $value): string {
    return str_replace(['|', "\n"], ['\|', ' '], (string) $value);
  }

  /**
   * Finds a resource by global resource id.
   */
  private function findResource(string $globalId): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('field_access_global_resource_id', $globalId)
      ->sort('nid')
      ->range(0, 1)
      ->execute();
    $node = $ids ? $storage->load(reset($ids)) : NULL;
    return $node instanceof NodeInterface ? $node : NULL;
  }

}
