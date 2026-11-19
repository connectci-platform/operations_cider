<?php

/**
 * @file
 * Deploy hooks for operations_cider.
 *
 * Deploy hooks run after config:import, so they can rely on fields, bundles
 * and search index changes that arrive through config sync.
 */

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
 * Moves resources from legacy queue hardware rows to node types (D8-3090).
 *
 * Each resource with legacy queue rows and no node types is curated (from
 * migration/d3090_curated.yml, when the hash of its legacy rows still
 * matches), migrated automatically (when QueueSpecMigrator raises no flags),
 * or skipped and left on its legacy rows. See NodeTypeMigration.
 *
 * This is a deploy hook rather than a post-update because the node type
 * fields arrive through config import, which drush deploy runs after
 * updatedb. It is safe to run twice: resources that already have node types
 * are not candidates. If any resource fails, the hook finishes the others and
 * then throws, so it is not recorded as run and the next deploy retries it.
 *
 * Run `drush operations_cider:d3090-plan` to preview what it will do.
 */
function operations_cider_deploy_d3090_01_node_types(array &$sandbox): string {
  /** @var \Drupal\operations_cider\NodeTypeMigration $migration */
  $migration = \Drupal::service('operations_cider.node_type_migration');
  $logger = \Drupal::logger('operations_cider');

  if (!isset($sandbox['ids'])) {
    // Snapshot the candidates once, so a resource that is skipped or fails
    // is not loaded again on every pass.
    $sandbox['ids'] = $migration->candidateIds();
    $sandbox['total'] = count($sandbox['ids']);
    $sandbox['counts'] = ['curated' => 0, 'automatic' => 0, 'skipped' => 0];
    $sandbox['failed'] = [];
  }

  $batch = array_splice($sandbox['ids'], 0, 10);
  $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($batch);
  foreach ($nodes as $node) {
    $context = ['@title' => $node->label(), '@nid' => $node->id()];
    try {
      $plan = $migration->plan($node);
      if ($plan['action'] === 'skipped') {
        $sandbox['counts']['skipped']++;
        $logger->warning('D8-3090 skipped @title (nid @nid): @reasons', $context + ['@reasons' => implode(' ', $plan['reasons'])]);
        continue;
      }
      $migration->apply($node, $plan['result'], $plan['action']);
      $sandbox['counts'][$plan['action']]++;
      $logger->notice('D8-3090 @action @title (nid @nid).@report', $context + [
        '@action' => $plan['action'],
        '@report' => $plan['reasons'] ? ' ' . implode(' ', $plan['reasons']) : '',
      ]);
    }
    catch (\Throwable $e) {
      $sandbox['failed'][] = sprintf('%s (nid %s): %s', $node->label(), $node->id(), $e->getMessage());
      $logger->error('D8-3090 failed for @title (nid @nid): @message', $context + ['@message' => $e->getMessage()]);
    }
  }

  $sandbox['#finished'] = $sandbox['ids'] ? 1 - count($sandbox['ids']) / max($sandbox['total'], 1) : 1;

  $summary = sprintf(
    'Node types: %d curated, %d automatic, %d skipped, %d failed of %d resources. See the operations_cider log for the per-resource report.',
    $sandbox['counts']['curated'],
    $sandbox['counts']['automatic'],
    $sandbox['counts']['skipped'],
    count($sandbox['failed']),
    $sandbox['total']
  );
  if ($sandbox['#finished'] >= 1 && $sandbox['failed']) {
    throw new \RuntimeException($summary . ' Failed: ' . implode('; ', $sandbox['failed']));
  }
  return $summary;
}
