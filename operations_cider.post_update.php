<?php

/**
 * @file
 * Post-update functions for operations_cider.
 *
 * Post-update hooks run during `drush deploy`'s updatedb step, BEFORE config
 * import. Anything that needs fields or bundles added through config sync
 * belongs in operations_cider.deploy.php instead.
 */

/**
 * Backfill field_cider_short_name on existing CIDeR resource nodes.
 *
 * Triggers a synchronous re-import of the cider_active_resources_feed so the
 * new short_name mapping populates the new field for every existing node.
 */
function operations_cider_post_update_backfill_short_name(): string {
  $feedStorage = \Drupal::entityTypeManager()->getStorage('feeds_feed');
  $feeds = $feedStorage->loadByProperties(['type' => 'cider_active_resources_feed']);
  if (empty($feeds)) {
    return 'No cider_active_resources_feed feed found — nothing to backfill.';
  }

  $imported = 0;
  foreach ($feeds as $feed) {
    $feed->import();
    $imported++;
  }

  return "Re-imported {$imported} CIDeR feed(s) to backfill field_cider_short_name.";
}

/**
 * Generate /documentation/resources/* aliases for RP documentation nodes.
 *
 * The pathauto pattern moved from `rp-documentation/[node:title]` to
 * `documentation/resources/[node:title]`. Existing aliases under
 * /rp-documentation are intentionally left in place so old links keep
 * working — they should be cleaned up separately a few weeks after launch.
 * This hook generates the new aliases for both content types so re-saving
 * each existing node manually is not required.
 */
function operations_cider_post_update_generate_documentation_resources_aliases(): string {
  if (!\Drupal::moduleHandler()->moduleExists('pathauto')) {
    return 'pathauto module not enabled — skipping alias regeneration.';
  }

  $generator = \Drupal::service('pathauto.generator');
  $storage = \Drupal::entityTypeManager()->getStorage('node');

  $created = 0;
  foreach (['access_active_resources_from_cid', 'resource_group'] as $bundle) {
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->execute();
    foreach ($storage->loadMultiple($nids) as $node) {
      // 'create' (not 'update') leaves existing aliases as-is and adds the
      // new pattern's alias on top — old /rp-documentation/* URLs keep
      // working until they're cleaned up later.
      if ($generator->createEntityAlias($node, 'create')) {
        $created++;
      }
    }
  }

  return "Generated {$created} new /documentation/resources/* aliases (existing /rp-documentation/* aliases preserved).";
}

/**
 * Backfill field_cider_short_name with node title where empty.
 *
 * The rp_documentation pathauto pattern uses [node:field_cider_short_name];
 * any access_active_resources_from_cid node without a short_name from CIDeR
 * needs a fallback so its alias is not generated as an empty slug.
 */
function operations_cider_post_update_d2719_01_backfill_short_name_with_title(): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $nids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'access_active_resources_from_cid')
    ->execute();

  $filled = 0;
  foreach ($storage->loadMultiple($nids) as $node) {
    if (trim((string) $node->get('field_cider_short_name')->value) !== '') {
      continue;
    }
    $node->set('field_cider_short_name', $node->getTitle());
    $node->save();
    $filled++;
  }

  return "Backfilled field_cider_short_name with title on {$filled} nodes.";
}

/**
 * Regenerate /documentation/resources/* aliases using new short_name pattern.
 *
 * The rp_documentation pattern now produces shorter slugs from
 * field_cider_short_name. Replace existing long-title aliases so the
 * canonical URL is the short one.
 */
function operations_cider_post_update_d2719_02_regenerate_short_aliases(): string {
  if (!\Drupal::moduleHandler()->moduleExists('pathauto')) {
    return 'pathauto module not enabled — skipping alias regeneration.';
  }

  $generator = \Drupal::service('pathauto.generator');
  $storage = \Drupal::entityTypeManager()->getStorage('node');

  $nids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'access_active_resources_from_cid')
    ->execute();

  $updated = 0;
  foreach ($storage->loadMultiple($nids) as $node) {
    if ($generator->updateEntityAlias($node, 'bulkupdate')) {
      $updated++;
    }
  }

  return "Regenerated {$updated} /documentation/resources/* aliases using short_name pattern.";
}

/**
 * Populate field_rp_sds_software so the feature is live at deploy time.
 *
 * The operations_cider_sds_software cron job only sweeps weekly, so without
 * this backfill the SDS software tables stay empty for up to a week after
 * deploy.
 */
function operations_cider_post_update_d2819_01_backfill_sds_software(): string {
  try {
    \Drupal::service('operations_cider.sds_software')->updateAll();
  }
  catch (\Throwable $e) {
    // Leave the state key unset so the next scheduled run of the
    // operations_cider_sds_software job picks the backfill back up.
    return 'SDS software backfill failed: ' . $e->getMessage() . ' — cron will retry.';
  }

  \Drupal::state()->set(
    'operations_cider.sds_software_last_run',
    \Drupal::time()->getRequestTime()
  );

  return 'Backfilled field_rp_sds_software from the Software Documentation Service.';
}

/**
 * Re-fetch SDS software now that the 200-entry cap is gone.
 *
 * Also runs the availability sweep, which now records the group name SDS
 * reports for each node; that sweep is weekly, so the sidebar CTA would
 * otherwise keep using the derived slug for up to a week after deploy.
 */
function operations_cider_post_update_d2819_02_uncap_sds_software(): string {
  try {
    \Drupal::service('operations_cider.sds_availability')->updateAll();
    \Drupal::service('operations_cider.sds_software')->updateAll();
  }
  catch (\Throwable $e) {
    // Leave the state key unset so the next scheduled cron job retries.
    return 'SDS software re-fetch failed: ' . $e->getMessage() . ' — cron will retry.';
  }

  \Drupal::state()->set(
    'operations_cider.sds_software_last_run',
    \Drupal::time()->getRequestTime()
  );

  return 'Re-fetched field_rp_sds_software without the entry cap.';
}
