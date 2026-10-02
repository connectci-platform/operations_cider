<?php

namespace Drupal\operations_cider\Plugin;

use Drupal\operations_cider\Exception\XdmodException;

/**
 * Manages cron job callbacks for the Operations CiDeR module.
 *
 * Each method is registered as an Ultimate Cron job callback so the schedule
 * lives in config (ultimate_cron.job.operations_cider_*) rather than in an
 * hour check inside hook_cron(). Jobs record a "last run" state key that the
 * post-update hooks read to decide whether a backfill is still needed.
 */
class CronManager {

  /**
   * Refresh queue metrics from XDMoD.
   */
  public static function updateQueueMetrics() {
    self::run('queue_metrics', 'operations_cider.queue_metrics', 'Queue metrics');
  }

  /**
   * Refresh top software from XDMoD, with SDS enrichment.
   */
  public static function updateTopSoftware() {
    self::run('top_software', 'operations_cider.top_software', 'Top software');
  }

  /**
   * Refresh Open OnDemand software enrichment.
   */
  public static function updateOodSoftware() {
    self::run('ood_software', 'operations_cider.ood_software', 'OOD software');
  }

  /**
   * Refresh the SDS software catalog for resources with no XDMoD data.
   *
   * Scheduled after the top software job so a resource that gains XDMoD data
   * in the same window is skipped rather than listed twice.
   */
  public static function updateSdsSoftware() {
    self::run('sds_software', 'operations_cider.sds_software', 'SDS software');
  }

  /**
   * Refresh the SDS availability map.
   */
  public static function updateSdsAvailability() {
    self::run('sds_availability', 'operations_cider.sds_availability', 'SDS availability');
  }

  /**
   * Runs a service's updateAll() and records the run timestamp.
   *
   * @param string $key
   *   Short job key, used to build the state key.
   * @param string $service_id
   *   Service to call updateAll() on.
   * @param string $label
   *   Human-readable job name, used in log messages.
   */
  protected static function run(string $key, string $service_id, string $label) {
    $logger = \Drupal::logger('operations_cider');

    try {
      \Drupal::service($service_id)->updateAll();
    }
    catch (\Throwable $e) {
      // Leave the state key untouched so a post-update backfill still sees the
      // data as stale, and let Ultimate Cron record the failure.
      $logger->error('@label update failed: @message', [
        '@label' => $label,
        '@message' => $e->getMessage(),
      ]);

      // Tell a human about XDMoD failures only (an expired token otherwise
      // fails silently every night). The notifier never throws, but guard it
      // anyway so the original exception is always the one that propagates.
      if ($e instanceof XdmodException) {
        try {
          \Drupal::service('operations_cider.xdmod_alert')->notify($label, $e);
        }
        catch (\Throwable $notify_error) {
          $logger->error('XDMoD alert failed: @message', [
            '@message' => $notify_error->getMessage(),
          ]);
        }
      }
      throw $e;
    }

    \Drupal::state()->set(
      'operations_cider.' . $key . '_last_run',
      \Drupal::time()->getRequestTime()
    );

    $logger->info('@label updated.', ['@label' => $label]);
  }

}
