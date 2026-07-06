<?php

namespace Drupal\operations_cider\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\operations_cider\ResourceContentHash;
use Drupal\operations_cider\Service\OodSoftwareService;
use Drupal\operations_cider\Service\ResourceGroupInheritanceService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST endpoints for resource provider documentation.
 *
 * Public JSON endpoints for content syndication to Elastic search
 * and the UKY RAG pipeline.
 */
class ResourceDocumentationController extends ControllerBase {

  /**
   * The resource group inheritance service.
   *
   * @var \Drupal\operations_cider\Service\ResourceGroupInheritanceService
   */
  protected ResourceGroupInheritanceService $inheritance;

  /**
   * The OOD software service.
   *
   * @var \Drupal\operations_cider\Service\OodSoftwareService
   */
  protected OodSoftwareService $oodSoftware;

  /**
   * Constructs a ResourceDocumentationController.
   */
  public function __construct(
    ResourceGroupInheritanceService $inheritance,
    OodSoftwareService $ood_software,
  ) {
    $this->inheritance = $inheritance;
    $this->oodSoftware = $ood_software;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('operations_cider.resource_group_inheritance'),
      $container->get('operations_cider.ood_software'),
    );
  }

  /**
   * GET /api/resources — list resources.
   *
   * By default, returns only resources whose `field_rp_description` is populated
   * (i.e. resources the team has written documentation for). Pass
   * `?documented=false` to include all CIDER-imported resources.
   */
  public function listResources(Request $request) {
    $documented = $request->query->get('documented', 'true');
    $documented_only = !in_array(strtolower((string) $documented), ['false', '0', 'no'], TRUE);

    $query = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('title');
    if ($documented_only) {
      // Match the in-PHP isEmpty() check used to populate has_documentation:
      // the field must be present and the value column must be non-empty.
      // Covers both NULL rows (never edited) and empty-string rows (cleared).
      $query->exists('field_rp_description.value');
      $query->condition('field_rp_description.value', '', '<>');
    }

    $nids = $query->execute();
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($nids);

    $resources = [];
    foreach ($nodes as $node) {
      $resources[] = [
        'nid' => (int) $node->id(),
        'title' => $node->getTitle(),
        'short_name' => $this->stringValue($node, 'field_cider_short_name'),
        'resource_id' => $node->get('field_cider_resource_id')->value,
        'global_resource_id' => $node->get('field_access_global_resource_id')->value,
        'org_name' => $node->get('field_access_org_name')->value,
        'status' => $node->get('field_cider_latest_status')->value,
        'resource_type' => $node->get('field_cider_resource_type')->value,
        'has_documentation' => !$node->get('field_rp_description')->isEmpty(),
        'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'last_modified' => date('c', $node->getChangedTime()),
      ];
    }

    $response = new CacheableJsonResponse([
      'count' => count($resources),
      'resources' => $resources,
    ]);
    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['node_list:access_active_resources_from_cid'])
      ->addCacheContexts(['url.query_args:documented']);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * GET /api/resources/{resource_id} — full resource detail.
   */
  public function getResource(string $resource_id) {
    $query = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('field_cider_resource_id', $resource_id)
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->range(0, 1);

    $nids = $query->execute();

    if (empty($nids)) {
      return new JsonResponse(['error' => 'Resource not found'], 404);
    }

    $node = $this->entityTypeManager()->getStorage('node')->load(reset($nids));
    $group = $this->inheritance->findParentGroup($node);

    // Apply Group-level inheritance to a clone so our in-memory mutations
    // don't leak into Drupal's static entity cache for downstream callers.
    $node = clone $node;
    $this->inheritance->applyInheritance($node);

    $changed = $node->getChangedTime();

    $data = [
      'nid' => (int) $node->id(),
      'title' => $node->getTitle(),
      'short_name' => $this->stringValue($node, 'field_cider_short_name'),
      'resource_id' => $node->get('field_cider_resource_id')->value,
      'global_resource_id' => $node->get('field_access_global_resource_id')->value,
      'resource_type' => $node->get('field_cider_resource_type')->value,
      'org_name' => $node->get('field_access_org_name')->value,
      'org_url' => $this->getLinkValue($node, 'field_access_org_url'),
      'status' => $node->get('field_cider_latest_status')->value,
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'last_modified' => date('c', $changed),
      'description' => $node->get('field_rp_description')->value,
      'mfa_required' => (bool) $node->get('field_rp_mfa_required')->value,
      'account_required' => (bool) $node->get('field_rp_account_required')->value,
      'ondemand_url' => $this->getLinkValue($node, 'field_rp_ondemand_url'),
      'office_hours' => $this->getLinkValue($node, 'field_rp_office_hours'),
      'login_text' => $this->getScalarValue($node, 'field_rp_login_text'),
      'file_transfer_text' => $this->getScalarValue($node, 'field_rp_file_transfer_text'),
      'jobs_info' => $this->getScalarValue($node, 'field_rp_jobs_info'),
      'software_list_url' => $this->getLinkValue($node, 'field_rp_software_list_url'),
      'external_storage' => $this->getScalarValue($node, 'field_rp_external_storage'),
      'ssh_logins' => $this->getParagraphData($node, 'field_rp_ssh_login_nodes', [
        'field_rp_ssh_hostname' => 'hostname',
        'field_rp_ssh_user_placeholder' => 'username_placeholder',
        'field_rp_ssh_docs_url' => 'docs_url',
        'field_rp_recommended' => 'recommended',
      ]),
      'login_help_links' => $this->getMultiLinkValues($node, 'field_rp_login_help_links'),
      'account_setup_url' => $this->getLinkValue($node, 'field_rp_account_setup_url'),
      'support_links' => $this->getMultiLinkValues($node, 'field_rp_support_links'),
      'file_transfer' => $this->getParagraphData($node, 'field_rp_file_transfer', [
        'field_rp_method' => 'method',
        'field_rp_transfer_node' => 'transfer_node',
        'field_rp_transfer_url' => 'transfer_url',
        'field_rp_recommended' => 'recommended',
      ]),
      'storage' => $this->getParagraphData($node, 'field_rp_storage', [
        'field_rp_directory' => 'directory',
        'field_rp_fs_path' => 'path',
        'field_rp_quota_size' => 'quota_size',
        'field_rp_quota_inode_amount' => 'quota_inode_amount',
        'field_rp_purge' => 'purge',
        'field_rp_backup' => 'backup',
        'field_rp_fs_notes' => 'notes',
      ]),
      'queue_specs' => $this->getParagraphData($node, 'field_rp_queue_specs', [
        'field_rp_queue_name' => 'name',
        'field_rp_queue_purpose' => 'purpose',
        'field_rp_node_count' => 'node_count',
        'field_rp_cpu_count' => 'cpu_count',
        'field_rp_cpus' => 'cpu_type',
        'field_rp_gpu_count' => 'gpu_count',
        'field_rp_gpus' => 'gpu_type',
        'field_rp_gpu_vram' => 'gpu_vram',
        'field_rp_vram' => 'ram',
        'field_rp_max_wall_time' => 'max_wall_time_hours',
      ]),
      'datasets' => $this->getParagraphData($node, 'field_rp_datasets', [
        'field_rp_dataset_name' => 'name',
        'field_rp_dataset_description' => 'description',
      ]),
    ];

    // Normalise and derive the wall-time pair for each queue spec.
    foreach ($data['queue_specs'] as &$q) {
      $hours = $q['max_wall_time_hours'] ?? NULL;
      $q['max_wall_time_hours'] = ($hours === NULL || $hours === '') ? NULL : (int) $hours;
      $q['max_wall_time_display'] = $this->wallTimeDisplay($q['max_wall_time_hours']);
    }
    unset($q);

    // Derived human-readable summaries. The main consumer ingests these into a
    // semantic database for an LLM; embeddings index prose better than JSON
    // keys, so a ready-made sentence retrieves better than the consumer
    // reassembling it from the raw (GB) fields. This is also where the
    // vram-is-really-RAM naming gets resolved once on our side.
    foreach ($data['queue_specs'] as &$queue) {
      $queue['summary'] = $this->buildQueueSummary($queue);
    }
    unset($queue);
    foreach ($data['storage'] as &$row) {
      $row['summary'] = $this->buildStorageSummary($row);
    }
    unset($row);

    // Include top software if available.
    $top_software = $node->get('field_rp_top_software')->value;
    if ($top_software) {
      $decoded = json_decode($top_software, TRUE);
      $data['top_software'] = is_array($decoded) ? $decoded : [];
    }
    else {
      $data['top_software'] = [];
    }

    // OOD software (same shape as top_software; sorted by job_count when set).
    // updateAll() pre-enriches and pre-sorts on cron (weekly throttle); the
    // controller sorts again at read time so the API is consistent immediately
    // even before cron runs. Consequence, same as top_software: a resource
    // whose OOD entries were just added shows them unsorted-by-usage and
    // unenriched until the next cron run — acceptable and matches the
    // established pattern.
    $ood_raw = $node->get('field_rp_ood_software')->value;
    $ood = $ood_raw ? json_decode($ood_raw, TRUE) : [];
    $data['ood_software'] = $this->oodSoftware->sortEntries(is_array($ood) ? $ood : []);

    $data['content_hash'] = ResourceContentHash::hash($data);

    $cacheability = (new CacheableMetadata())->addCacheableDependency($node);
    if ($group) {
      // Inherited values mean edits to the Group must invalidate this response.
      $cacheability->addCacheableDependency($group);
    }

    $response = new CacheableJsonResponse($data);
    // ETag/Last-Modified are advisory revalidation hints. content_hash in the
    // body is the authoritative change signal (a node's changed time can bump
    // on no-op saves and can be stripped by the page cache layer), so we do not
    // build literal 304 handling here.
    $response->headers->set('ETag', '"' . $node->id() . '-' . $changed . '"');
    $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $changed) . ' GMT');
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * GET /api/resource-groups — list resource groups with aggregated sections.
   *
   * Returns one entry per `resource_group` node, with:
   * - `slug`: stable URL slug derived from the group title via Html::getClass().
   *   Matches the `data-resource-context` attribute set by aspTheme so the
   *   QA bot can look up the same key the embedded bot reports.
   * - `title`: clean human-readable group title (e.g. "Anvil", "Bridges-2").
   * - `populated_sections`: union of documented section types across all
   *   member CIDER variants. Used by the agent to build resource-scoped
   *   capability menus.
   * - `variants`: list of member CIDER variants for traceability.
   */
  public function listResourceGroups() {
    // Field-to-section mapping. The agent's RPSectionCache uses these
    // identifiers to build the section_question_map for scoped capabilities.
    $section_fields = [
      'field_rp_ssh_login_nodes' => 'login',
      'field_rp_file_transfer' => 'file_transfer',
      'field_rp_storage' => 'storage',
      'field_rp_queue_specs' => 'queue_specs',
      'field_rp_top_software' => 'top_software',
      'field_rp_datasets' => 'datasets',
    ];

    $node_storage = $this->entityTypeManager()->getStorage('node');
    $query = $node_storage
      ->getQuery()
      ->condition('type', 'resource_group')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('title');
    $group_nids = $query->execute();
    $groups = $node_storage->loadMultiple($group_nids);

    $result = [];
    foreach ($groups as $group) {
      $title = $group->getTitle();
      $slug = Html::getClass($title);
      $variant_refs = $group->get('field_cider_resources');

      $variants = [];
      $populated = [];
      foreach ($variant_refs as $ref) {
        $variant = $ref->entity;
        if (!$variant) {
          continue;
        }
        $variants[] = [
          'nid' => (int) $variant->id(),
          'title' => $variant->getTitle(),
          'short_name' => $this->stringValue($variant, 'field_cider_short_name'),
          'resource_id' => $variant->get('field_cider_resource_id')->value,
          'global_resource_id' => $variant->get('field_access_global_resource_id')->value,
        ];

        // Check which section fields have content on this variant.
        foreach ($section_fields as $field_name => $section_id) {
          if (in_array($section_id, $populated, TRUE)) {
            continue;
          }
          if ($variant->hasField($field_name) && !$variant->get($field_name)->isEmpty()) {
            $populated[] = $section_id;
          }
        }
      }

      $result[] = [
        'nid' => (int) $group->id(),
        'slug' => $slug,
        'title' => $title,
        'url' => $group->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'populated_sections' => $populated,
        'variants' => $variants,
      ];
    }

    $response = new CacheableJsonResponse([
      'count' => count($result),
      'groups' => $result,
    ]);
    $cacheability = (new CacheableMetadata())->addCacheTags([
      'node_list:resource_group',
      'node_list:access_active_resources_from_cid',
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Extract a scalar (text/long/string) field value, safe on missing fields.
   */
  private function getScalarValue($node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }
    return $node->get($field_name)->value;
  }

  /**
   * Safely read a string field, returning NULL if empty or missing.
   */
  private function stringValue($node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }
    return $node->get($field_name)->value ?: NULL;
  }

  /**
   * Extract a single link field value.
   */
  private function getLinkValue($node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = $node->get($field_name)->first();
    return $value ? $value->getUrl()->toString() : NULL;
  }

  /**
   * Extract multi-value link field values.
   */
  private function getMultiLinkValues($node, string $field_name): array {
    $links = [];
    if (!$node->hasField($field_name)) {
      return $links;
    }
    foreach ($node->get($field_name) as $item) {
      try {
        $url = $item->getUrl()->toString();
      }
      catch (\Exception $e) {
        // Fall back to raw URI if URL generation fails (e.g. external URIs).
        $url = $item->uri;
      }
      $links[] = [
        'title' => $item->title,
        'url' => $url,
      ];
    }
    return $links;
  }

  /**
   * Extract paragraph reference field data into flat arrays.
   */
  private function getParagraphData($node, string $field_name, array $field_map): array {
    $items = [];
    $paragraph_storage = $this->entityTypeManager()->getStorage('paragraph');
    foreach ($node->get($field_name) as $ref) {
      $paragraph = $paragraph_storage->loadRevision($ref->target_revision_id);
      if (!$paragraph) {
        continue;
      }
      $row = [];
      foreach ($field_map as $paragraph_field => $key) {
        if ($paragraph->hasField($paragraph_field)) {
          $field = $paragraph->get($paragraph_field);
          if ($field->getFieldDefinition()->getType() === 'boolean') {
            $row[$key] = (bool) $field->value;
          }
          elseif ($field->getFieldDefinition()->getType() === 'integer') {
            // Keep counts/sizes typed so consumers can filter and compute
            // without re-parsing strings. Sizes stay raw and in GB.
            $row[$key] = $field->isEmpty() ? NULL : (int) $field->value;
          }
          elseif ($field->getFieldDefinition()->getType() === 'link') {
            $row[$key] = $field->isEmpty() ? NULL : $field->first()->getUrl()->toString();
          }
          else {
            $row[$key] = $field->value;
          }
        }
      }
      if (array_filter($row, fn($v) => $v !== NULL && $v !== '')) {
        $items[] = $row;
      }
    }
    return $items;
  }

  /**
   * Format a GB integer as decimal TB when it divides evenly into 1000 GB.
   *
   * Storage capacity convention: 1 TB = 1000 GB. Raw fields stay in GB; only
   * the derived summary strings use this. Drupal's format_size() expects bytes
   * and uses binary units, so it can't be reused here.
   */
  private function formatGbSize(int $gb): string {
    if ($gb !== 0 && $gb % 1000 === 0) {
      return intdiv($gb, 1000) . ' TB';
    }
    return $gb . ' GB';
  }

  /**
   * Build a human-readable one-line summary of a queue spec.
   *
   * E.g. "4 NVIDIA A100 (80 GB vRAM), 2 nodes, 64-core AMD EPYC 7763, 256 GB RAM".
   */
  private function buildQueueSummary(array $queue): ?string {
    $parts = [];

    // Render GPU type/vRAM even when the per-node count is absent; the count
    // is an optional prefix.
    if (!empty($queue['gpu_count']) || !empty($queue['gpu_type']) || !empty($queue['gpu_vram'])) {
      $gpu = '';
      if (!empty($queue['gpu_count'])) {
        $gpu = (string) $queue['gpu_count'];
      }
      if (!empty($queue['gpu_type'])) {
        $gpu = $gpu === '' ? $queue['gpu_type'] : $gpu . ' ' . $queue['gpu_type'];
      }
      if (!empty($queue['gpu_vram'])) {
        $gpu .= ' (' . $queue['gpu_vram'] . ' GB vRAM)';
      }
      $parts[] = trim($gpu);
    }

    if (isset($queue['node_count']) && $queue['node_count'] !== NULL && $queue['node_count'] !== '') {
      $parts[] = (int) $queue['node_count'] . ' nodes';
    }

    if (!empty($queue['cpu_count']) && !empty($queue['cpu_type'])) {
      $parts[] = $queue['cpu_count'] . '-core ' . $queue['cpu_type'];
    }
    elseif (!empty($queue['cpu_type'])) {
      $parts[] = $queue['cpu_type'];
    }
    elseif (!empty($queue['cpu_count'])) {
      $parts[] = $queue['cpu_count'] . '-core';
    }

    // field_rp_vram holds node RAM (GB), despite its legacy field name.
    if (isset($queue['ram']) && $queue['ram'] !== NULL && $queue['ram'] !== '') {
      $parts[] = $this->formatGbSize((int) $queue['ram']) . ' RAM';
    }

    return $parts ? implode(', ', $parts) : NULL;
  }

  /**
   * Build a human-readable one-line summary of a storage filesystem row.
   *
   * E.g. "25 GB, 1,000,000 files".
   */
  private function buildStorageSummary(array $row): ?string {
    $parts = [];
    if (isset($row['quota_size']) && $row['quota_size'] !== NULL && $row['quota_size'] !== '') {
      $parts[] = $this->formatGbSize((int) $row['quota_size']);
    }
    if (isset($row['quota_inode_amount']) && $row['quota_inode_amount'] !== NULL && $row['quota_inode_amount'] !== '') {
      $parts[] = number_format((int) $row['quota_inode_amount']) . ' files';
    }
    return $parts ? implode(', ', $parts) : NULL;
  }

  /**
   * Human-readable duration for a whole-hours wall-time limit.
   */
  private function wallTimeDisplay(?int $hours): ?string {
    if ($hours === NULL || $hours <= 0) {
      return NULL;
    }
    return $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
  }

}
