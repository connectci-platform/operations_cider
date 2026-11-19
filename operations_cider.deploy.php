<?php

/**
 * @file
 * Deploy hooks for operations_cider.
 *
 * Deploy hooks run after config:import, so they can rely on fields, bundles
 * and search index changes that arrive through config sync.
 */

use Drupal\operations_cider\QueueSpecMigrator;
use Drupal\search_api\Entity\Index;

/**
 * Reindex the default search index to populate field_rp_display_name.
 *
 * D8-2735/D8-2820 adds field_rp_display_name to the default index and removes
 * the node_load setTitle mutation, so the indexed `title` also changes from
 * the short name to the descriptive name. A reindex is required to pick both
 * up. reindex() only marks items for reindexing; cron drains the queue.
 */
function operations_cider_deploy_reindex_resource_display_name() {
  $index = Index::load('default');
  if (!$index) {
    return 'search_api index "default" not found; skipped reindex.';
  }
  $index->reindex();
  return 'Marked search index "default" for reindexing to pick up resource display name.';
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
 *
 * This is a deploy hook rather than a post-update because field_rp_node_types
 * and the new paragraph types arrive through config import, which drush
 * deploy runs after updatedb.
 */
function operations_cider_deploy_d3090_01_node_types(&$sandbox): string {
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
