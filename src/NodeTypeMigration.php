<?php

namespace Drupal\operations_cider;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Moves a resource from legacy queue hardware rows to node types (D8-3090).
 *
 * Each resource that has legacy queue rows and no node types is handled in
 * one of three ways:
 * - curated: migration/d3090_curated.yml has a hand-written entry for its
 *   global resource id, and the hash of its legacy rows still matches the
 *   hash the entry was written from;
 * - automatic: QueueSpecMigrator raises no flags, so its merge is safe;
 * - skipped: anything else. The resource keeps its legacy rows, which the
 *   API, the theme and the MCP search still render as before.
 *
 * Shared by the deploy hook and the drush commands that help write and
 * review the curated file.
 */
final class NodeTypeMigration {

  /**
   * The curated file, relative to the module directory.
   */
  public const CURATED_FILE = 'migration/d3090_curated.yml';

  /**
   * Allowed values of the list fields, keyed by the migration's keys.
   */
  public const ALLOWED = [
    'cpu_architecture' => ['x86_64', 'arm'],
    'charged_per' => ['core', 'gpu', 'node'],
    'access' => ['batch', 'interactive', 'by_request'],
    'accelerator_type' => ['gpu', 'fpga', 'vector_engine', 'other'],
  ];

  /**
   * Curated entries keyed by global resource id; NULL until loaded.
   *
   * @var array<string, array<string, mixed>>|null
   */
  private ?array $curated = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Replaces the curated entries, for tests.
   *
   * @param array<string, array<string, mixed>> $curated
   *   Entries keyed by global resource id.
   */
  public function setCurated(array $curated): void {
    $this->curated = $curated;
  }

  /**
   * The curated entries, loaded from the module's file on first use.
   *
   * @return array<string, array<string, mixed>>
   *   Entries keyed by global resource id.
   */
  public function curated(): array {
    if ($this->curated === NULL) {
      $path = $this->curatedPath();
      $parsed = is_file($path) ? Yaml::parseFile($path) : [];
      if (!is_array($parsed)) {
        throw new \UnexpectedValueException(sprintf('%s must hold a map keyed by global resource id.', self::CURATED_FILE));
      }
      $this->curated = $parsed;
    }
    return $this->curated;
  }

  /**
   * Absolute path of the curated file.
   */
  public function curatedPath(): string {
    return DRUPAL_ROOT . '/' . $this->moduleList->getPath('operations_cider') . '/' . self::CURATED_FILE;
  }

  /**
   * Ids of resources that still have legacy queue rows and no node types.
   *
   * @return list<int>
   *   Node ids, ascending.
   */
  public function candidateIds(): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'access_active_resources_from_cid')
      ->exists('field_rp_queue_specs')
      ->notExists('field_rp_node_types')
      ->sort('nid')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Reads a resource's legacy queue rows in editor order.
   *
   * @return list<array<string, mixed>>
   *   Rows in the QueueSpecMigrator shape.
   */
  public function legacyRows(NodeInterface $node): array {
    $rows = [];
    foreach ($node->get('field_rp_queue_specs')->referencedEntities() as $paragraph) {
      $rows[] = $this->legacyRow($paragraph);
    }
    return $rows;
  }

  /**
   * Decides how a resource would be migrated, without changing it.
   *
   * @return array{action: string, reasons: list<string>, hash: ?string, global_id: ?string, result: ?array<string, mixed>}
   *   action is curated, automatic or skipped. result is the node types and
   *   queues to write (NULL when skipped). reasons explains a skip; for an
   *   automatic migration it holds the migrator's report lines.
   */
  public function plan(NodeInterface $node): array {
    $globalId = $node->hasField('field_access_global_resource_id')
      ? $this->text($node->get('field_access_global_resource_id')->value)
      : NULL;
    $plan = [
      'action' => 'skipped',
      'reasons' => [],
      'hash' => NULL,
      'global_id' => $globalId,
      'result' => NULL,
    ];

    if (!$node->get('field_rp_node_types')->isEmpty()) {
      $plan['reasons'][] = 'Already has node types.';
      return $plan;
    }
    $rows = $this->legacyRows($node);
    if (!$rows) {
      $plan['reasons'][] = 'Its queue rows no longer load.';
      return $plan;
    }
    $plan['hash'] = QueueSpecMigrator::legacyHash($rows);

    $curated = $globalId !== NULL ? ($this->curated()[$globalId] ?? NULL) : NULL;
    if (is_array($curated)) {
      if (($curated['legacy_hash'] ?? NULL) !== $plan['hash']) {
        $plan['reasons'][] = sprintf('Its legacy rows changed after the curated entry was written (hash %s, entry has %s).', $plan['hash'], $curated['legacy_hash'] ?? 'none');
        return $plan;
      }
      $plan['action'] = 'curated';
      $plan['result'] = [
        'node_types' => $curated['node_types'] ?? [],
        'queues' => $curated['queues'] ?? [],
      ];
      return $plan;
    }

    $migrator = new QueueSpecMigrator();
    $flags = $migrator->flags($rows);
    if ($flags) {
      $plan['reasons'] = array_merge(['Flagged and no curated entry.'], $flags);
      return $plan;
    }
    $result = $migrator->migrate($rows);
    $plan['action'] = 'automatic';
    $plan['reasons'] = $result['report'];
    $plan['result'] = [
      'node_types' => $result['node_types'],
      'queues' => $result['queues'],
    ];
    return $plan;
  }

  /**
   * Checks a migration result before it is written.
   *
   * @param array<string, mixed> $result
   *   The node_types and queues, in the curated file's shape.
   *
   * @return list<string>
   *   Errors; empty when valid.
   */
  public function validate(array $result): array {
    $errors = [];
    $nodeTypes = $result['node_types'] ?? NULL;
    $queues = $result['queues'] ?? NULL;
    if (!is_array($nodeTypes) || !array_is_list($nodeTypes) || !is_array($queues) || !array_is_list($queues)) {
      return ['node_types and queues must both be lists.'];
    }

    $queueNames = [];
    foreach ($queues as $i => $queue) {
      if (!is_array($queue)) {
        $errors[] = sprintf('Queue %d is not a map.', $i);
        continue;
      }
      $name = $this->text($queue['name'] ?? NULL);
      if ($name === NULL) {
        $errors[] = sprintf('Queue %d has no name.', $i);
        continue;
      }
      $queueNames[] = $name;
      $errors = array_merge($errors, $this->enumErrors('charged_per', $queue['charged_per'] ?? NULL, sprintf('Queue "%s"', $name)));
      $errors = array_merge($errors, $this->enumErrors('access', $queue['access'] ?? NULL, sprintf('Queue "%s"', $name)));
      $errors = array_merge($errors, $this->intErrors($queue, ['max_wall_minutes'], sprintf('Queue "%s"', $name)));
    }

    $labels = [];
    $references = [];
    foreach ($nodeTypes as $i => $type) {
      if (!is_array($type)) {
        $errors[] = sprintf('Node type %d is not a map.', $i);
        continue;
      }
      $label = $this->text($type['label'] ?? NULL);
      if ($label === NULL) {
        $errors[] = sprintf('Node type %d has no label.', $i);
        continue;
      }
      $labels[] = $label;
      $where = sprintf('Node type "%s"', $label);
      $errors = array_merge($errors, $this->enumErrors('cpu_architecture', $type['cpu_architecture'] ?? NULL, $where));
      $errors = array_merge($errors, $this->intErrors($type, ['node_count', 'cpu_count', 'ram'], $where));
      foreach ($type['accelerators'] ?? [] as $accelerator) {
        if (!is_array($accelerator)) {
          $errors[] = sprintf('%s has an accelerator that is not a map.', $where);
          continue;
        }
        if (($accelerator['type'] ?? NULL) === NULL) {
          $errors[] = sprintf('%s has an accelerator with no type.', $where);
        }
        $errors = array_merge($errors, $this->enumErrors('accelerator_type', $accelerator['type'] ?? NULL, $where));
        $errors = array_merge($errors, $this->intErrors($accelerator, ['count', 'memory'], $where . ' accelerator'));
      }
      $names = [];
      foreach ($type['queues'] ?? [] as $entry) {
        if (!is_array($entry)) {
          $errors[] = sprintf('%s has a queue entry that is not a map.', $where);
          continue;
        }
        $names[] = (string) ($entry['name'] ?? '');
        $errors = array_merge($errors, $this->intErrors($entry, ['node_count'], $where . ' queue entry'));
      }
      $references[] = ['label' => $label, 'queues' => $names];
    }

    return array_merge($errors, NodeTypeReferences::errors($labels, $queueNames, $references));
  }

  /**
   * Writes a migration result to a resource and saves it.
   *
   * Builds every paragraph before touching the node, so a failure leaves the
   * resource as it was. Reuses the first legacy paragraph of each queue name,
   * which keeps its old hardware fields until the cleanup ticket; the other
   * rows of that queue, and queues the result leaves out, are dropped from
   * the field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The resource.
   * @param array<string, mixed> $result
   *   The node_types and queues.
   * @param string $action
   *   The plan action, curated or automatic, for the revision log.
   *
   * @throws \InvalidArgumentException
   *   When the result does not validate.
   */
  public function apply(NodeInterface $node, array $result, string $action): void {
    $errors = $this->validate($result);
    if ($errors) {
      throw new \InvalidArgumentException(implode(' ', $errors));
    }
    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');

    $nodeTypes = [];
    foreach ($result['node_types'] as $type) {
      $accelerators = [];
      foreach ($type['accelerators'] ?? [] as $accelerator) {
        $accelerators[] = $paragraphStorage->create([
          'type' => 'rp_accelerator',
          'field_rp_accelerator_type' => $accelerator['type'],
          'field_rp_accelerator_model' => $this->text($accelerator['model'] ?? NULL),
          'field_rp_accelerator_count' => $accelerator['count'] ?? NULL,
          'field_rp_accelerator_memory' => $accelerator['memory'] ?? NULL,
        ]);
      }
      $entries = [];
      foreach ($type['queues'] ?? [] as $entry) {
        $entries[] = $paragraphStorage->create([
          'type' => 'rp_node_type_queue',
          'field_rp_queue_name' => NodeTypeReferences::normalize((string) $entry['name']),
          'field_rp_node_count' => $entry['node_count'] ?? NULL,
        ]);
      }
      $nodeTypes[] = $paragraphStorage->create([
        'type' => 'rp_node_type',
        'field_rp_node_type_label' => NodeTypeReferences::normalize((string) $type['label']),
        'field_rp_node_type_description' => $this->text($type['description'] ?? NULL),
        'field_rp_node_count' => $type['node_count'] ?? NULL,
        'field_rp_cpus' => $this->text($type['cpu_type'] ?? NULL),
        'field_rp_cpu_architecture' => $type['cpu_architecture'] ?? NULL,
        'field_rp_cpu_count' => $type['cpu_count'] ?? NULL,
        'field_rp_node_ram' => $type['ram'] ?? NULL,
        'field_rp_accelerators' => $accelerators,
        'field_rp_node_type_queues' => $entries,
      ]);
    }

    $firstByName = [];
    foreach ($node->get('field_rp_queue_specs')->referencedEntities() as $paragraph) {
      $name = NodeTypeReferences::normalize((string) $paragraph->get('field_rp_queue_name')->value);
      $firstByName[$name] ??= $paragraph;
    }
    $queueParagraphs = [];
    foreach ($result['queues'] as $queue) {
      $name = NodeTypeReferences::normalize((string) $queue['name']);
      $paragraph = $firstByName[$name] ?? $paragraphStorage->create([
        'type' => 'rp_queue_spec',
        'field_rp_queue_name' => $name,
      ]);
      $paragraph->set('field_rp_queue_purpose', $this->text($queue['purpose'] ?? NULL));
      $paragraph->set('field_rp_max_wall_time', $queue['max_wall_minutes'] ?? NULL);
      $paragraph->set('field_rp_charged_per', $queue['charged_per'] ?? NULL);
      $paragraph->set('field_rp_queue_access', $queue['access'] ?? NULL);
      if (!$paragraph->isNew()) {
        $paragraph->setNewRevision(TRUE);
      }
      $queueParagraphs[] = $paragraph;
    }

    $node->set('field_rp_node_types', $nodeTypes);
    $node->set('field_rp_queue_specs', $queueParagraphs);
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage(sprintf('Split queue hardware into node types (D8-3090, %s).', $action));
    $node->save();
  }

  /**
   * Reads a legacy rp_queue_spec paragraph into the QueueSpecMigrator shape.
   *
   * @return array<string, mixed>
   *   Row keyed like the API; empty values are NULL and numbers are integers.
   */
  public function legacyRow(ParagraphInterface $paragraph): array {
    $text = fn (string $field): ?string => $this->text($paragraph->get($field)->value);
    $int = static function (string $field) use ($paragraph): ?int {
      $value = $paragraph->get($field)->value;
      return $value === NULL || $value === '' ? NULL : (int) $value;
    };
    return [
      'name' => (string) $paragraph->get('field_rp_queue_name')->value,
      'purpose' => $text('field_rp_queue_purpose'),
      'node_count' => $int('field_rp_node_count'),
      'cpu_type' => $text('field_rp_cpus'),
      'cpu_count' => $int('field_rp_cpu_count'),
      'gpu_type' => $text('field_rp_gpus'),
      'gpu_count' => $int('field_rp_gpu_count'),
      'gpu_vram' => $int('field_rp_gpu_vram'),
      'ram' => $int('field_rp_vram'),
      'legacy_ram' => $text('field_rp_ram'),
      'max_wall_minutes' => $int('field_rp_max_wall_time'),
    ];
  }

  /**
   * Errors for a list value outside the allowed values.
   *
   * @param string $key
   *   The ALLOWED key.
   * @param mixed $value
   *   The value to check.
   * @param string $where
   *   What holds the value, for the message.
   *
   * @return list<string>
   *   Zero or one error.
   */
  private function enumErrors(string $key, mixed $value, string $where): array {
    if ($value === NULL || in_array($value, self::ALLOWED[$key], TRUE)) {
      return [];
    }
    return [sprintf('%s has %s "%s"; allowed: %s.', $where, $key, is_scalar($value) ? (string) $value : gettype($value), implode(', ', self::ALLOWED[$key]))];
  }

  /**
   * Errors for keys that must be blank or a non-negative integer.
   *
   * @param array<string, mixed> $values
   *   The map to check.
   * @param list<string> $keys
   *   Keys to check.
   * @param string $where
   *   What holds the values, for the message.
   *
   * @return list<string>
   *   Errors.
   */
  private function intErrors(array $values, array $keys, string $where): array {
    $errors = [];
    foreach ($keys as $key) {
      $value = $values[$key] ?? NULL;
      if ($value !== NULL && (!is_int($value) || $value < 0)) {
        $errors[] = sprintf('%s has %s "%s"; it must be blank or a whole number of 0 or more.', $where, $key, is_scalar($value) ? (string) $value : gettype($value));
      }
    }
    return $errors;
  }

  /**
   * Trims text; empty becomes NULL.
   */
  private function text(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $value = trim((string) $value);
    return $value === '' ? NULL : $value;
  }

}
