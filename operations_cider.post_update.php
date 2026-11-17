<?php

/**
 * @file
 * Post-update functions for operations_cider.
 *
 * post_update hooks run after `drush deploy`'s config import step, so we can
 * rely on new fields and feed mappings being in place before we touch data.
 */

use Drupal\operations_cider\QueueSpecMigrator;

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

/**
 * Split queue hardware into node types and queue node references (D8-3090).
 *
 * Each legacy rp_queue_spec row repeated its hardware. This groups the rows by
 * hardware into rp_node_type paragraphs (with nested rp_node_gpu paragraphs)
 * and keeps one rp_queue_spec per queue name, pointing at node types through
 * rp_queue_node rows. The old hardware fields are left untouched for the
 * cleanup ticket. Resources that already have node types are skipped, so the
 * update is safe to rerun.
 */
function operations_cider_post_update_d3090_01_node_types(&$sandbox): string {
  $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
  $logger = \Drupal::logger('operations_cider');

  $candidates = static function () use ($nodeStorage, &$sandbox) {
    $query = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'access_active_resources_from_cid')
      ->exists('field_rp_queue_specs')
      ->notExists('field_rp_node_types')
      ->sort('nid');
    // Leave out resources already handled without being migrated, or each
    // pass would load the same ones again and the update would never finish.
    $done = array_merge($sandbox['failed'] ?? [], $sandbox['skipped'] ?? []);
    if ($done) {
      $query->condition('nid', $done, 'NOT IN');
    }
    return $query;
  };

  if (!isset($sandbox['resources'])) {
    $sandbox['resources'] = 0;
    $sandbox['node_types'] = 0;
    $sandbox['queues'] = 0;
    $sandbox['failed'] = [];
    $sandbox['skipped'] = [];
    $sandbox['total'] = (int) $candidates()->count()->execute();
  }

  $nids = $candidates()->range(0, 10)->execute();
  $migrator = new QueueSpecMigrator();
  $paragraphStorage = \Drupal::entityTypeManager()->getStorage('paragraph');

  foreach ($nodeStorage->loadMultiple($nids) as $node) {
    try {
      // Re-check: an earlier pass or another process may have migrated it.
      if (!$node->get('field_rp_node_types')->isEmpty()) {
        $sandbox['skipped'][] = $node->id();
        continue;
      }

      $paragraphs = [];
      $rows = [];
      foreach ($node->get('field_rp_queue_specs')->referencedEntities() as $paragraph) {
        $paragraphs[] = $paragraph;
        $rows[] = _operations_cider_d3090_legacy_row($paragraph);
      }
      if (!$rows) {
        // The queue references no longer load (deleted paragraphs), so there
        // is nothing to migrate.
        $sandbox['skipped'][] = $node->id();
        $logger->warning('Node types migration skipped @title (nid @nid): its queue rows no longer load.', [
          '@title' => $node->label(),
          '@nid' => $node->id(),
        ]);
        continue;
      }
      $result = $migrator->migrate($rows);

      // Build everything before touching the node so a failure leaves it as is.
      $nodeTypes = [];
      foreach ($result['node_types'] as $type) {
        $gpus = [];
        foreach ($type['gpus'] as $gpu) {
          $gpus[] = $paragraphStorage->create([
            'type' => 'rp_node_gpu',
            'field_rp_gpus' => $gpu['type'],
            'field_rp_gpu_count' => $gpu['count'],
            'field_rp_gpu_vram' => $gpu['vram'],
          ]);
        }
        $nodeTypes[] = $paragraphStorage->create([
          'type' => 'rp_node_type',
          'field_rp_node_type_label' => $type['label'],
          'field_rp_node_type_description' => $type['description'],
          'field_rp_node_count' => $type['node_count'],
          'field_rp_cpus' => $type['cpu_type'],
          'field_rp_cpu_count' => $type['cpu_count'],
          'field_rp_vram' => $type['ram'],
          'field_rp_node_gpus' => $gpus,
        ]);
      }

      // Reuse the first existing paragraph of each queue name; the other rows
      // of that queue are dropped from the field (not deleted).
      $firstByName = [];
      foreach ($paragraphs as $paragraph) {
        $name = trim((string) $paragraph->get('field_rp_queue_name')->value);
        $firstByName[$name] ??= $paragraph;
      }
      $queueParagraphs = [];
      foreach ($result['queues'] as $queue) {
        $queueNodes = [];
        foreach ($queue['nodes'] as $entry) {
          $queueNodes[] = $paragraphStorage->create([
            'type' => 'rp_queue_node',
            'field_rp_queue_node_type' => $entry['node_type'],
            'field_rp_node_count' => $entry['node_count'],
          ]);
        }
        $paragraph = $firstByName[$queue['name']];
        $paragraph->set('field_rp_queue_purpose', $queue['purpose']);
        $paragraph->set('field_rp_max_wall_time', $queue['max_wall_minutes']);
        $paragraph->set('field_rp_queue_nodes', $queueNodes);
        $paragraph->setNewRevision(TRUE);
        $queueParagraphs[] = $paragraph;
      }

      $node->set('field_rp_node_types', $nodeTypes);
      $node->set('field_rp_queue_specs', $queueParagraphs);
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Split queue hardware into node types (D8-3090)');
      $node->save();

      foreach ($result['report'] as $line) {
        $logger->notice('@title: @line', ['@title' => $node->label(), '@line' => $line]);
      }
      $sandbox['resources']++;
      $sandbox['node_types'] += count($nodeTypes);
      $sandbox['queues'] += count($queueParagraphs);
    }
    catch (\Throwable $e) {
      $sandbox['failed'][] = $node->id();
      $logger->error('Node types migration failed for @title (nid @nid): @message', [
        '@title' => $node->label(),
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  $remaining = (int) $candidates()->count()->execute();
  $sandbox['#finished'] = $remaining === 0 ? 1 : 1 - ($remaining / max($sandbox['total'], 1));
  if ($remaining > 0 && $sandbox['#finished'] >= 1) {
    $sandbox['#finished'] = 0.99;
  }

  return sprintf(
    'Migrated %d resources into %d node types and %d queues (%d failed, %d skipped; see the operations_cider log for the report).',
    $sandbox['resources'],
    $sandbox['node_types'],
    $sandbox['queues'],
    count($sandbox['failed']),
    count($sandbox['skipped'])
  );
}

/**
 * Reads a legacy rp_queue_spec paragraph into the QueueSpecMigrator row shape.
 *
 * @param \Drupal\paragraphs\ParagraphInterface $paragraph
 *   The queue row.
 *
 * @return array<string, mixed>
 *   Row keyed like the API; empty values are NULL and numbers are integers.
 */
function _operations_cider_d3090_legacy_row($paragraph): array {
  $text = static function (string $field) use ($paragraph): ?string {
    $value = $paragraph->get($field)->value;
    return $value === NULL || trim((string) $value) === '' ? NULL : (string) $value;
  };
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
