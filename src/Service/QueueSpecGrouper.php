<?php

namespace Drupal\operations_cider\Service;

/**
 * Groups resource queue-spec rows by queue name for display.
 *
 * A resource's `field_rp_queue_specs` paragraphs are one row per
 * queue-and-hardware pair, but the XDMoD metrics attached to them (wait time,
 * wall time, job count) are measured per queue. Rendering a row per paragraph
 * therefore repeats the same queue-level numbers on every hardware row of a
 * multi-hardware queue, which reads as several independently-measured queues
 * that happen to share a name.
 *
 * This collapses the flat list into one group per distinct queue name so the
 * queue-level data renders once, above its hardware rows. Presentation only:
 * nothing here writes, and the paragraphs are passed through untouched.
 */
class QueueSpecGrouper {

  /**
   * Groups queue-spec rows by queue name.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Ordered list of rows, each an array with:
   *   - name: the queue name. Rows with a blank name are skipped.
   *   - purpose: the row's purpose text, or '' / NULL.
   *   - max_wall: max wallclock in whole minutes, or NULL.
   *   - paragraph: opaque; passed straight through for the template.
   * @param array<string, mixed> $metrics
   *   The decoded queue-metrics lookup, keyed by queue name.
   *
   * @return array<int, array<string, mixed>>
   *   Ordered list of groups, one per distinct non-empty name, in order of
   *   first appearance. Each group has:
   *   - name: the queue name.
   *   - metrics: $metrics[name], or NULL.
   *   - rows: that queue's rows, in editor order.
   *   - purpose: the purpose shared by every row, or NULL when they differ.
   *   - max_wall: the max wall shared by every row, or NULL when they differ.
   *   - single: TRUE when the queue has exactly one row.
   */
  public static function group(array $rows, array $metrics): array {
    $groups = [];
    foreach ($rows as $row) {
      // Names are compared as exact strings: two spellings of the same queue
      // are two queues as far as the metrics lookup is concerned, so they stay
      // two groups here too.
      $name = isset($row['name']) ? trim((string) $row['name']) : '';
      if ($name === '') {
        continue;
      }
      // Keyed by name so a non-contiguous repeat (A, B, A) merges back into
      // A's group rather than opening a third one.
      if (!isset($groups[$name])) {
        $groups[$name] = [
          'name' => $name,
          'metrics' => $metrics[$name] ?? NULL,
          'rows' => [],
        ];
      }
      $groups[$name]['rows'][] = $row;
    }

    foreach ($groups as &$group) {
      $group['purpose'] = self::sharedValue($group['rows'], 'purpose');
      $group['max_wall'] = self::sharedValue($group['rows'], 'max_wall');
      $group['single'] = count($group['rows']) === 1;
    }
    unset($group);

    // Drop the name keys: a numeric-looking queue name ("1", "2") would
    // otherwise make PHP renumber and reorder the list on the way to Twig.
    return array_values($groups);
  }

  /**
   * The value every row shares for a key, or NULL when they differ.
   *
   * A blank never counts as shared — [48h, blank] is a mixed group, not a 48h
   * one — so a group only hoists a value onto its heading row when the value
   * genuinely describes all of it.
   *
   * @param array<int, array<string, mixed>> $rows
   *   The group's rows.
   * @param string $key
   *   The row key to compare.
   *
   * @return mixed
   *   The shared value, or NULL.
   */
  private static function sharedValue(array $rows, string $key) {
    $shared = NULL;
    foreach ($rows as $row) {
      $value = $row[$key] ?? NULL;
      if ($value === NULL || $value === '' || (is_string($value) && trim($value) === '')) {
        return NULL;
      }
      if ($shared === NULL) {
        $shared = $value;
      }
      elseif ($shared != $value) {
        return NULL;
      }
    }
    return $shared;
  }

}
