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
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $updated, new \DateTimeZone('UTC'));
    if ($date === FALSE || $date->format('Y-m-d') !== $updated) {
      return FALSE;
    }
    return ($now - $date->getTimestamp()) > $threshold_days * 86400;
  }

}
