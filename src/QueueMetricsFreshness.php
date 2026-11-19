<?php

namespace Drupal\operations_cider;

/**
 * Decides whether cached XDMoD queue metrics are too old to show as current.
 *
 * The metrics summarise a trailing 30-day window and are refreshed nightly. If
 * the refresh stops (an expired XDMoD token, for one) the last good figures
 * stay on the node indefinitely, so the page needs a cutoff past which the
 * averages and sparklines no longer describe the queue. Pure (no Drupal deps)
 * so the threshold is unit-testable.
 */
final class QueueMetricsFreshness {

  /**
   * Days after the last refresh before the metrics count as stale.
   *
   * Half again the 30-day window, so a few missed nightly runs don't flip the
   * page, but a month-long outage does.
   */
  public const STALE_AFTER_DAYS = 45;

  /**
   * Label key: the date is the last day XDMoD reported data.
   */
  public const LABEL_DATA_THROUGH = 'data_through';

  /**
   * Label key: the date is only when the metrics were last fetched.
   */
  public const LABEL_UPDATED = 'updated';

  /**
   * Whether metrics last refreshed on $updated are stale at $now.
   *
   * @param string|null $updated
   *   The refresh date (YYYY-MM-DD) stored in field_rp_queue_metrics, or NULL
   *   when the node has none.
   * @param int $now
   *   The current Unix timestamp.
   * @param int $threshold_days
   *   Days after which the metrics are stale.
   *
   * @return bool
   *   TRUE when the date is older than the threshold. FALSE when there is no
   *   date, or one that isn't YYYY-MM-DD: QueueMetricsService always writes
   *   that format, so anything else (the amp_dev fixture's "2 hours ago") has
   *   no age to judge and is left alone rather than hidden.
   */
  public static function isStale(?string $updated, int $now, int $threshold_days = self::STALE_AFTER_DAYS): bool {
    if ($updated === NULL || $updated === '') {
      return FALSE;
    }
    $date = self::parseDate($updated);
    if ($date === NULL) {
      return FALSE;
    }
    return ($now - $date->getTimestamp()) > $threshold_days * 86400;
  }

  /**
   * Picks the date the theme should judge staleness by, and how to label it.
   *
   * Prefers `last_data`, the last day XDMoD reported data. It is NULL when the
   * two timeseries requests fail but the aggregates succeed, and absent from
   * blobs written before the key existed. In both cases, and when it isn't a
   * strict YYYY-MM-DD date, fall back to `updated`, the fetch date, with a
   * different label so the note doesn't claim data through that day.
   *
   * @param array<string, mixed> $blob
   *   The decoded field_rp_queue_metrics blob.
   *
   * @return array
   *   The date and label.
   *
   * @phpstan-return array{date: string|null, label: string}
   */
  public static function freshnessDate(array $blob): array {
    $last_data = $blob['last_data'] ?? NULL;
    if (is_string($last_data) && self::parseDate($last_data) !== NULL) {
      return ['date' => $last_data, 'label' => self::LABEL_DATA_THROUGH];
    }
    $updated = $blob['updated'] ?? NULL;
    return [
      'date' => is_string($updated) && $updated !== '' ? $updated : NULL,
      'label' => self::LABEL_UPDATED,
    ];
  }

  /**
   * Parses a strict YYYY-MM-DD date in UTC.
   *
   * @param string $value
   *   The candidate date.
   *
   * @return \DateTimeImmutable|null
   *   The date at midnight UTC, or NULL when it isn't a real YYYY-MM-DD date.
   */
  private static function parseDate(string $value): ?\DateTimeImmutable {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
    if ($date === FALSE || $date->format('Y-m-d') !== $value) {
      return NULL;
    }
    return $date;
  }

}
