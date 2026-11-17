<?php

namespace Drupal\operations_cider;

/**
 * Converts legacy per-queue hardware rows into node types and queues.
 *
 * Each legacy row repeated its hardware, so a resource with five queues on the
 * same nodes entered the same CPU and GPU five times. This groups the rows by
 * hardware into node types (entered once) and by queue name into queues that
 * point at node types with a per-queue node count. It is pure PHP so the rules
 * can be unit tested against real rows; the post_update applies the result.
 */
final class QueueSpecMigrator {

  /**
   * GPU type text that means "no accelerator described".
   */
  private const PLACEHOLDERS = ['none', 'n/a', 'na', '-'];

  /**
   * Migrates legacy rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Legacy rows in editor order, keyed like the API: name, purpose,
   *   node_count, cpu_type, cpu_count, gpu_type, gpu_count, gpu_vram, ram,
   *   max_wall_minutes, and optionally legacy_ram (the deprecated RAM text).
   *
   * @return array{node_types: list<array<string, mixed>>, queues: list<array<string, mixed>>, report: list<string>}
   *   Node types, queues, and human-readable report lines.
   */
  public function migrate(array $rows): array {
    $report = [];
    $nodeTypes = [];
    // Hardware tuple key => index in $nodeTypes, for merging.
    $tupleIndex = [];
    // Queue key => ['name' => string, 'rows' => list<array>].
    $queueGroups = [];

    foreach (array_values($rows) as $raw) {
      $row = $this->normalizeRow($raw);
      $queueKey = 'q:' . $row['name'];
      $queueGroups[$queueKey]['name'] = $row['name'];

      $tuple = [
        $row['cpu_type'], $row['cpu_count'], $row['ram'],
        $row['gpu_type'], $row['gpu_count'], $row['gpu_vram'],
      ];
      $allBlank = count(array_filter($tuple, static fn ($v) => $v !== NULL)) === 0;

      if ($allBlank) {
        // Never merge: the row says nothing about its hardware.
        $index = $this->addNodeType($nodeTypes, $row, $row['name'] !== '' ? $row['name'] : 'Standard', $report);
        $report[] = sprintf('Queue "%s" has no hardware entered; made its own node type "%s".', $row['name'], $nodeTypes[$index]['label']);
      }
      else {
        $key = 't:' . json_encode($tuple);
        if (isset($tupleIndex[$key])) {
          $index = $tupleIndex[$key];
        }
        else {
          $base = $row['gpu_type'] ?? $row['cpu_type'] ?? 'Standard';
          $index = $this->addNodeType($nodeTypes, $row, $base, $report);
          $tupleIndex[$key] = $index;
        }
      }
      $row['label'] = $nodeTypes[$index]['label'];
      $row['type_index'] = $index;
      $queueGroups[$queueKey]['rows'][] = $row;

      if ($row['ram'] === NULL && $row['legacy_ram'] !== NULL) {
        $report[] = sprintf('Queue "%s" has RAM text "%s" but no Node RAM (GB); enter the number on node type "%s".', $row['name'], $row['legacy_ram'], $row['label']);
      }
    }

    $queues = [];
    foreach (array_values($queueGroups) as $group) {
      $queues[] = $this->buildQueue($group['name'], $group['rows'], $nodeTypes, $report);
    }
    $this->reportSharedCpuOnlyTypes($nodeTypes, $queues, $report);

    return [
      'node_types' => array_values($nodeTypes),
      'queues' => $queues,
      'report' => array_values($report),
    ];
  }

  /**
   * Flags no-GPU node types that several queues share on an accelerator system.
   *
   * On a resource that also has accelerators, queues without a GPU model often
   * run on hardware the GPU fields could not describe (ACES bittware, memverge),
   * so identical CPU fields may hide different nodes. Merging is still the
   * right default, as on Delta CPU; this asks a person to check.
   *
   * @param array<int, array<string, mixed>> $nodeTypes
   *   The node types.
   * @param array<int, array<string, mixed>> $queues
   *   The queues.
   * @param string[] $report
   *   Report lines, appended to.
   */
  private function reportSharedCpuOnlyTypes(array $nodeTypes, array $queues, array &$report): void {
    $hasAccelerator = (bool) array_filter($nodeTypes, static fn ($type) => $type['gpus'] !== []);
    if (!$hasAccelerator) {
      return;
    }
    foreach ($nodeTypes as $type) {
      if ($type['gpus'] !== []) {
        continue;
      }
      $users = [];
      foreach ($queues as $queue) {
        if (in_array($type['label'], array_column($queue['nodes'], 'node_type'), TRUE)) {
          $users[] = $queue['name'];
        }
      }
      if (count($users) > 1) {
        $report[] = sprintf('Queues "%s" share node type "%s", which lists no GPU. If they run on different hardware (an FPGA or other accelerator), split it into one node type per queue.', implode('", "', $users), $type['label']);
      }
    }
  }

  /**
   * Builds one queue from its rows, applying the purpose and wall-time rules.
   *
   * @param string $name
   *   The queue name.
   * @param array<int, array<string, mixed>> $rows
   *   The normalized rows of this queue.
   * @param array<int, array<string, mixed>> $nodeTypes
   *   Node types, modified when row purposes become descriptions.
   * @param string[] $report
   *   Report lines, appended to.
   *
   * @return array<string, mixed>
   *   The queue.
   */
  private function buildQueue(string $name, array $rows, array &$nodeTypes, array &$report): array {
    $purposes = array_values(array_unique(array_map(static fn ($r) => $r['purpose'], $rows), SORT_REGULAR));
    if (count($purposes) <= 1) {
      $purpose = $purposes[0] ?? NULL;
    }
    else {
      $purpose = NULL;
      foreach ($rows as $row) {
        if ($row['purpose'] !== NULL && $nodeTypes[$row['type_index']]['description'] === NULL) {
          $nodeTypes[$row['type_index']]['description'] = $row['purpose'];
        }
      }
      $report[] = sprintf('Queue "%s" had %d different purposes across its rows; left the queue purpose blank and moved them to node type descriptions where those were empty.', $name, count($purposes));
    }

    $walls = array_map(static fn ($r) => $r['max_wall_minutes'], $rows);
    $distinct = array_values(array_unique($walls, SORT_REGULAR));
    $wall = NULL;
    foreach ($walls as $candidate) {
      if ($candidate !== NULL) {
        $wall = $candidate;
        break;
      }
    }
    if (count($distinct) > 1) {
      $report[] = sprintf('Queue "%s" had different max wall times across its rows (%s); used %s.', $name, implode(', ', array_map(static fn ($v) => $v === NULL ? 'blank' : (string) $v, $distinct)), $wall === NULL ? 'blank' : (string) $wall);
    }

    $nodes = [];
    foreach ($rows as $row) {
      $nodes[] = ['node_type' => $row['label'], 'node_count' => $row['node_count']];
    }

    return [
      'name' => $name,
      'purpose' => $purpose,
      'max_wall_minutes' => $wall,
      'nodes' => $nodes,
    ];
  }

  /**
   * Adds a node type built from a row and returns its index.
   *
   * @param array<int, array<string, mixed>> $nodeTypes
   *   Node types, appended to.
   * @param array<string, mixed> $row
   *   The normalized row supplying the hardware.
   * @param string $base
   *   The preferred label before collision handling.
   * @param string[] $report
   *   Report lines, appended to.
   *
   * @return int
   *   The index of the new node type.
   */
  private function addNodeType(array &$nodeTypes, array $row, string $base, array &$report): int {
    $label = $this->uniqueLabel($nodeTypes, $base, $row['ram'], $report);

    $gpus = [];
    if ($row['gpu_placeholder'] === NULL && $row['gpu_type'] !== NULL) {
      $models = preg_split('/\s*(?:,|\s\+\s)\s*/', $row['gpu_type']) ?: [];
      $models = array_values(array_filter(array_map('trim', $models), static fn ($m) => $m !== ''));
      if (count($models) > 1) {
        foreach ($models as $model) {
          $gpus[] = ['type' => $model, 'count' => NULL, 'vram' => NULL];
        }
        $report[] = sprintf(
          'Node type "%s" lists several GPU models ("%s", count %s, vRAM %s); split into %d GPUs with blank count and vRAM. Fill them in.',
          $label,
          $row['gpu_type'],
          $row['gpu_count'] ?? 'blank',
          $row['gpu_vram'] ?? 'blank',
          count($models)
        );
      }
      else {
        $gpus[] = ['type' => $row['gpu_type'], 'count' => $row['gpu_count'], 'vram' => $row['gpu_vram']];
      }
    }
    elseif ($row['gpu_placeholder'] === NULL && ($row['gpu_count'] !== NULL || $row['gpu_vram'] !== NULL)) {
      $report[] = sprintf('Node type "%s" had GPU count %s and vRAM %s but no GPU type; dropped them. Add the GPU model by hand.', $label, $row['gpu_count'] ?? 'blank', $row['gpu_vram'] ?? 'blank');
    }

    $nodeTypes[] = [
      'label' => $label,
      'description' => NULL,
      'node_count' => NULL,
      'cpu_type' => $row['cpu_type'],
      'cpu_count' => $row['cpu_count'],
      'ram' => $row['ram'],
      'gpus' => $gpus,
    ];
    return count($nodeTypes) - 1;
  }

  /**
   * Picks a label unique among the node types so far.
   *
   * The first type to claim a label keeps it bare. A later collision gets
   * " (<ram> GB RAM)" when that tells the types apart, else " <n>" from 2.
   *
   * @param array<int, array<string, mixed>> $nodeTypes
   *   Node types so far.
   * @param string $base
   *   The preferred label.
   * @param int|null $ram
   *   The new type's node RAM in GB.
   * @param string[] $report
   *   Report lines, appended to.
   *
   * @return string
   *   A unique label.
   */
  private function uniqueLabel(array $nodeTypes, string $base, ?int $ram, array &$report): string {
    $base = NodeTypeReferences::normalize($base);
    $taken = array_column($nodeTypes, 'label');
    if (!in_array($base, $taken, TRUE)) {
      return $base;
    }

    // The RAM suffix only helps if no type already sharing this base has the
    // same RAM; otherwise the labels would read as duplicates anyway.
    $siblingRams = [];
    foreach ($nodeTypes as $type) {
      if ($type['label'] === $base || str_starts_with($type['label'], $base . ' (') || preg_match('/^' . preg_quote($base, '/') . ' \d+$/', $type['label'])) {
        $siblingRams[] = $type['ram'];
      }
    }
    if ($ram !== NULL && !in_array($ram, $siblingRams, TRUE)) {
      $candidate = sprintf('%s (%d GB RAM)', $base, $ram);
      if (!in_array($candidate, $taken, TRUE)) {
        $report[] = sprintf('Label "%s" was already used; named this node type "%s" to tell them apart.', $base, $candidate);
        return $candidate;
      }
    }
    $n = 2;
    while (in_array($base . ' ' . $n, $taken, TRUE)) {
      $n++;
    }
    $candidate = $base . ' ' . $n;
    $report[] = sprintf('Label "%s" was already used with the same RAM; named this node type "%s". Rename it to say what differs.', $base, $candidate);
    return $candidate;
  }

  /**
   * Normalizes a raw row: trims text, casts integers, flags placeholders.
   *
   * @param array<string, mixed> $raw
   *   The raw row.
   *
   * @return array<string, mixed>
   *   The normalized row, plus gpu_placeholder (the original placeholder text
   *   or NULL).
   */
  private function normalizeRow(array $raw): array {
    $gpuType = $this->text($raw['gpu_type'] ?? NULL);
    $placeholder = NULL;
    if ($gpuType !== NULL && in_array(strtolower($gpuType), self::PLACEHOLDERS, TRUE)) {
      $placeholder = $gpuType;
      $gpuType = NULL;
    }
    $gpuCount = $this->int($raw['gpu_count'] ?? NULL);
    $gpuVram = $this->int($raw['gpu_vram'] ?? NULL);
    if ($gpuType === NULL && $gpuCount === 0) {
      $gpuCount = NULL;
    }
    if ($gpuType === NULL && $gpuVram === 0) {
      $gpuVram = NULL;
    }
    if ($placeholder !== NULL) {
      $gpuCount = NULL;
      $gpuVram = NULL;
    }

    return [
      'name' => NodeTypeReferences::normalize((string) ($raw['name'] ?? '')),
      'purpose' => $this->text($raw['purpose'] ?? NULL),
      'node_count' => $this->int($raw['node_count'] ?? NULL),
      'cpu_type' => $this->text($raw['cpu_type'] ?? NULL),
      'cpu_count' => $this->int($raw['cpu_count'] ?? NULL),
      'gpu_type' => $gpuType,
      'gpu_count' => $gpuCount,
      'gpu_vram' => $gpuVram,
      'gpu_placeholder' => $placeholder,
      'ram' => $this->int($raw['ram'] ?? NULL),
      'legacy_ram' => $this->text($raw['legacy_ram'] ?? NULL),
      'max_wall_minutes' => $this->int($raw['max_wall_minutes'] ?? NULL),
    ];
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

  /**
   * Casts a stored integer; empty becomes NULL.
   */
  private function int(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return (int) $value;
  }

}
