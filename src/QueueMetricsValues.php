<?php

namespace Drupal\operations_cider;

/**
 * Value rules for XDMoD queue metrics.
 *
 * XDMoD reports "no jobs" as a bare 0 and omits rows after its last data day,
 * so a stored 0 can mean "missing" rather than "zero hours". These rules turn
 * missing data into NULL so the page never shows a misleading 0. Pure (no
 * Drupal deps) so they are unit-testable.
 */
final class QueueMetricsValues {

  /**
   * Parses one CSV cell into a float, or NULL when it carries no data.
   *
   * A bare "0" means no jobs that day, so it is NULL. A decimal zero such as
   * "0.00000000" is a real numeric zero. Empty and non-numeric cells are NULL.
   *
   * @param string $cell
   *   The raw cell text.
   *
   * @return float|null
   *   The value, or NULL when missing.
   */
  public static function parseCell(string $cell): ?float {
    $cell = trim($cell);
    if ($cell === '' || $cell === '0' || !is_numeric($cell)) {
      return NULL;
    }
    return (float) $cell;
  }

  /**
   * Applies the wait-time rule: an exact zero wait is implausible.
   *
   * Some resources (Delta) report no wait data at all, which XDMoD renders as
   * 0.0. Treat it as missing rather than "no wait".
   *
   * @param float|null $value
   *   A wait time in hours.
   *
   * @return float|null
   *   The value, or NULL when it is NULL or exactly zero.
   */
  public static function waitOrNull(?float $value): ?float {
    return $value === NULL || $value == 0.0 ? NULL : $value;
  }

  /**
   * Finds the latest date that has any wait or wall value in any queue.
   *
   * @param array<string, array<string, mixed>> $queues
   *   Queue data, each with parallel "daily_dates", "daily_wait" and
   *   "daily_wall" lists.
   *
   * @return string|null
   *   The latest YYYY-MM-DD with data, or NULL when there is none.
   */
  public static function lastDataDate(array $queues): ?string {
    $latest = NULL;
    foreach ($queues as $queue) {
      foreach ($queue['daily_dates'] ?? [] as $i => $date) {
        $has_data = ($queue['daily_wait'][$i] ?? NULL) !== NULL
          || ($queue['daily_wall'][$i] ?? NULL) !== NULL;
        if ($has_data && ($latest === NULL || $date > $latest)) {
          $latest = $date;
        }
      }
    }
    return $latest;
  }

}
