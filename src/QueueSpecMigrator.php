<?php

namespace Drupal\operations_cider;

/**
 * Converts legacy per-queue hardware rows into node types and queues.
 *
 * Each legacy row repeated its hardware, so a resource with five queues on the
 * same nodes entered the same CPU and GPU five times. This groups the rows by
 * hardware into node types (entered once), each listing the queues it serves
 * with a per-queue node count, and by queue name into queues. It is pure PHP so
 * the rules can be unit tested against real rows; the deploy hook applies the
 * result. Resources where merging is not safe are reported by flags() and are
 * not merged automatically.
 */
final class QueueSpecMigrator {

  /**
   * GPU type text that means "no accelerator described".
   */
  private const PLACEHOLDERS = ['none', 'n/a', 'na', '-', '0'];

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

      $tuple = $this->tuple($row);
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
      if ($row['name'] === '') {
        // Hardware with no queue (Anvil Notebook has no scheduler): a node
        // type that serves no queue, with the row's count as its total.
        unset($queueGroups[$queueKey]);
        $nodeTypes[$index]['node_count'] ??= $row['node_count'];
        $report[] = sprintf('A row has no queue name; its hardware became node type "%s", which serves no queue.', $row['label']);
        continue;
      }
      $nodeTypes[$index]['queues'][] = ['name' => $row['name'], 'node_count' => $row['node_count']];
      $queueGroups[$queueKey]['rows'][] = $row;

      if ($row['ram'] === NULL && $row['legacy_ram'] !== NULL) {
        $report[] = sprintf('Queue "%s" has RAM text "%s" but no Node RAM (GB); enter the number on node type "%s".', $row['name'], $row['legacy_ram'], $row['label']);
      }
    }

    $queues = [];
    foreach (array_values($queueGroups) as $group) {
      $queues[] = $this->buildQueue($group['name'], $group['rows'], $report);
    }

    return [
      'node_types' => array_values($nodeTypes),
      'queues' => $queues,
      'report' => array_values($report),
    ];
  }

  /**
   * Lists the reasons a resource must not be merged automatically.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Legacy rows, as for migrate().
   *
   * @return list<string>
   *   Human-readable reasons; empty when the rows are safe to merge.
   */
  public function flags(array $rows): array {
    $flags = [];
    $normalized = [];
    foreach (array_values($rows) as $raw) {
      $normalized[] = $this->normalizeRow($raw);
    }

    // (a) Placeholder accelerator values.
    $seen = [];
    foreach ($normalized as $row) {
      if ($row['gpu_placeholder'] !== NULL && !isset($seen[$row['name'] . "\0" . $row['gpu_placeholder']])) {
        $seen[$row['name'] . "\0" . $row['gpu_placeholder']] = TRUE;
        $flags[] = sprintf('Queue "%s" has the placeholder accelerator value "%s".', $row['name'], $row['gpu_placeholder']);
      }
    }

    // (b) Rows of one queue that disagree on purpose or wall time.
    $groups = [];
    foreach ($normalized as $row) {
      $groups['q:' . $row['name']][] = $row;
    }
    foreach ($groups as $group) {
      $name = $group[0]['name'];
      $purposes = array_values(array_unique(array_map(static fn ($r) => $r['purpose'], $group), SORT_REGULAR));
      if (count($purposes) > 1) {
        $flags[] = sprintf('Queue "%s" has rows with different purposes.', $name);
      }
      $walls = array_values(array_unique(array_map(static fn ($r) => $r['max_wall_minutes'], $group), SORT_REGULAR));
      if (count($walls) > 1) {
        $flags[] = sprintf('Queue "%s" has rows with different max wall times (%s).', $name, implode(', ', array_map(static fn ($v) => $v === NULL ? 'blank' : (string) $v, $walls)));
      }
    }

    // (c) Hardware that differs only in formatting.
    $variants = [];
    foreach ($normalized as $row) {
      $tuple = $this->tuple($row);
      if (count(array_filter($tuple, static fn ($v) => $v !== NULL)) === 0) {
        continue;
      }
      $key = json_encode(array_map([$this, 'comparable'], $tuple));
      $variants[$key][json_encode($tuple)] = $row['name'];
    }
    foreach ($variants as $byTuple) {
      if (count($byTuple) < 2) {
        continue;
      }
      $descriptions = [];
      foreach ($byTuple as $json => $queueName) {
        $descriptions[] = sprintf('"%s" (queue "%s")', implode(' / ', array_map(static fn ($v) => $v ?? 'blank', json_decode($json, TRUE))), $queueName);
      }
      $flags[] = 'Hardware differs only in formatting: ' . implode(' vs ', $descriptions) . '.';
    }

    return $flags;
  }

  /**
   * Hashes legacy rows so a curated mapping can detect later edits.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Legacy rows in editor order, with scalar or NULL values.
   *
   * @return string
   *   A sha256 hex digest, sensitive to row order.
   */
  public static function legacyHash(array $rows): string {
    $sorted = [];
    foreach (array_values($rows) as $row) {
      ksort($row);
      $sorted[] = $row;
    }
    return hash('sha256', json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
  }

  /**
   * The hardware tuple of a normalized row.
   *
   * @param array<string, mixed> $row
   *   A normalized row.
   *
   * @return list<mixed>
   *   CPU type, CPU count, RAM, GPU type, GPU count, GPU vRAM.
   */
  private function tuple(array $row): array {
    return [
      $row['cpu_type'], $row['cpu_count'], $row['ram'],
      $row['gpu_type'], $row['gpu_count'], $row['gpu_vram'],
    ];
  }

  /**
   * Normalizes a tuple value for formatting-insensitive comparison.
   *
   * Lowercases, drops parenthesized segments and collapses anything that is not
   * a letter or digit.
   *
   * @param mixed $value
   *   A tuple value.
   *
   * @return string|null
   *   The comparable form, or NULL for blank.
   */
  private function comparable(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $text = mb_strtolower((string) $value);
    $text = preg_replace('/\([^)]*\)/', ' ', $text) ?? $text;
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    return trim($text);
  }

  /**
   * Builds one queue, using the first row's purpose and wall time.
   *
   * @param string $name
   *   The queue name.
   * @param array<int, array<string, mixed>> $rows
   *   The normalized rows of this queue.
   * @param string[] $report
   *   Report lines, appended to.
   *
   * @return array<string, mixed>
   *   The queue.
   */
  private function buildQueue(string $name, array $rows, array &$report): array {
    return [
      'name' => $name,
      'purpose' => $rows[0]['purpose'],
      'max_wall_minutes' => $rows[0]['max_wall_minutes'],
      'charged_per' => NULL,
      'access' => NULL,
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

    $accelerators = [];
    if ($row['gpu_placeholder'] === NULL && $row['gpu_type'] !== NULL) {
      $models = preg_split('/\s*(?:,|\s\+\s)\s*/', $row['gpu_type']) ?: [];
      $models = array_values(array_filter(array_map('trim', $models), static fn ($m) => $m !== ''));
      if (count($models) > 1) {
        foreach ($models as $model) {
          $accelerators[] = ['type' => 'gpu', 'model' => $model, 'count' => NULL, 'memory' => NULL];
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
        $accelerators[] = [
          'type' => 'gpu',
          'model' => $row['gpu_type'],
          'count' => $row['gpu_count'],
          'memory' => $row['gpu_vram'],
        ];
      }
    }
    elseif ($row['gpu_placeholder'] === NULL && ($row['gpu_count'] !== NULL || $row['gpu_vram'] !== NULL)) {
      // A GPU count with no model (Expanse's ind-gpu) still documents GPUs;
      // dropping it would make the queue stop matching a GPU search.
      $accelerators[] = [
        'type' => 'gpu',
        'model' => NULL,
        'count' => $row['gpu_count'],
        'memory' => $row['gpu_vram'],
      ];
      $report[] = sprintf('Node type "%s" has GPU count %s and vRAM %s but no GPU model; kept them on a GPU with a blank model. Add the model.', $label, $row['gpu_count'] ?? 'blank', $row['gpu_vram'] ?? 'blank');
    }

    $nodeTypes[] = [
      'label' => $label,
      'description' => NULL,
      'node_count' => NULL,
      'cpu_type' => $row['cpu_type'],
      'cpu_architecture' => NULL,
      'cpu_count' => $row['cpu_count'],
      'ram' => $row['ram'],
      'accelerators' => $accelerators,
      'queues' => [],
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
