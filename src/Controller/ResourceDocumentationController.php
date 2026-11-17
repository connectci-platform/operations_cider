<?php

namespace Drupal\operations_cider\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\operations_cider\NodeTypeReferences;
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
   * Section key => inheritable link field backing the page's "Documentation"
   * link for that section. Order here is the order in the payload.
   */
  private const DOCUMENTATION_LINK_FIELDS = [
    'login' => 'field_rp_login_to_anvil_gpu_link',
    'ssh_login' => 'field_rp_ssh_login_link',
    'ondemand_login' => 'field_rp_access_ood_login_link',
    'submitting_jobs' => 'field_rp_submitting_jobs',
    'queue_specs' => 'field_rp_queue_spec_link',
    'software' => 'field_rp_software_link',
    'top_software' => 'field_rp_freq_used_software_link',
    'storage' => 'field_rp_storage_link',
    'file_system' => 'field_rp_file_system_link',
    'external_storage' => 'field_rp_external_storage_link',
    'file_transfer' => 'field_rp_file_transfer_link',
    'datasets' => 'field_rp_datasets_link',
  ];

  /**
   * Domain id whose public documentation index defines "listed".
   *
   * Mirrors the filter in views.view.rp_documentation_index (the
   * /documentation/resources page): published resources and resource groups
   * with field_rp_listing set and field_domain_access containing this domain.
   */
  private const LISTED_DOMAIN_ID = 'amp_cyberinfrastructure_org';

  /**
   * Section ids for populated_sections, in output order.
   *
   * The agent's RPSectionCache uses these identifiers to build the
   * section_question_map for scoped capabilities, so do not rename them.
   */
  private const SECTION_ORDER = [
    'login',
    'file_transfer',
    'storage',
    'queue_specs',
    'top_software',
    'datasets',
  ];

  /**
   * Paragraph field => payload key for SSH login node rows.
   */
  private const SSH_LOGIN_MAP = [
    'field_rp_ssh_hostname' => 'hostname',
    'field_rp_ssh_user_placeholder' => 'username_placeholder',
    'field_rp_ssh_docs_url' => 'docs_url',
    'field_rp_recommended' => 'recommended',
  ];

  /**
   * Paragraph field => payload key for file transfer rows.
   */
  private const FILE_TRANSFER_MAP = [
    'field_rp_method' => 'method',
    'field_rp_transfer_node' => 'transfer_node',
    'field_rp_transfer_url' => 'transfer_url',
    'field_rp_recommended' => 'recommended',
  ];

  /**
   * Paragraph field => payload key for storage rows.
   */
  private const STORAGE_MAP = [
    'field_rp_directory' => 'directory',
    'field_rp_fs_path' => 'path',
    'field_rp_quota_size' => 'quota_size',
    'field_rp_quota_inode_amount' => 'quota_inode_amount',
    'field_rp_purge' => 'purge',
    'field_rp_backup' => 'backup',
    'field_rp_fs_notes' => 'notes',
  ];

  /**
   * Paragraph field => payload key for queue spec rows.
   */
  private const QUEUE_SPEC_MAP = [
    'field_rp_queue_name' => 'name',
    'field_rp_queue_purpose' => 'purpose',
    'field_rp_node_count' => 'node_count',
    'field_rp_cpu_count' => 'cpu_count',
    'field_rp_cpus' => 'cpu_type',
    'field_rp_gpu_count' => 'gpu_count',
    'field_rp_gpus' => 'gpu_type',
    'field_rp_gpu_vram' => 'gpu_vram',
    'field_rp_vram' => 'ram',
    'field_rp_max_wall_time' => 'max_wall_time_minutes',
  ];

  /**
   * Paragraph field => payload key for dataset rows.
   */
  private const DATASET_MAP = [
    'field_rp_dataset_name' => 'name',
    'field_rp_dataset_description' => 'description',
  ];

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
      ->accessCheck(TRUE);
    if ($documented_only) {
      // Match the in-PHP isEmpty() check used to populate has_documentation:
      // the field must be present and the value column must be non-empty.
      // Covers both NULL rows (never edited) and empty-string rows (cleared).
      $query->exists('field_rp_description.value');
      $query->condition('field_rp_description.value', '', '<>');
    }

    $nids = $query->execute();
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($nids);

    // One group query for the whole list; membership is a set lookup below.
    $listed_member_nids = $this->listedGroupMemberNids();

    $resources = [];
    foreach ($nodes as $node) {
      $resources[] = [
        'nid' => (int) $node->id(),
        'title' => operations_cider_resource_display_name($node),
        'short_name' => $this->stringValue($node, 'field_cider_short_name'),
        'resource_id' => $node->get('field_cider_resource_id')->value,
        'global_resource_id' => $node->get('field_access_global_resource_id')->value,
        'org_name' => $node->get('field_access_org_name')->value,
        'status' => $node->get('field_cider_latest_status')->value,
        'resource_type' => $node->get('field_cider_resource_type')->value,
        'has_documentation' => !$node->get('field_rp_description')->isEmpty(),
        'listed' => $this->isListed($node, $listed_member_nids),
        'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'last_modified' => date('c', $node->getChangedTime()),
      ];
    }

    // Sort by the display title actually returned (field_rp_display_name ->
    // field_cider_short_name -> raw title), not the DB title used to build
    // the query above — an entity query can't express that fallback in SQL.
    usort($resources, fn(array $a, array $b) => strcasecmp($a['title'], $b['title']));

    $response = new CacheableJsonResponse([
      'count' => count($resources),
      'resources' => $resources,
    ]);
    $cacheability = (new CacheableMetadata())
      ->addCacheTags([
        'node_list:access_active_resources_from_cid',
        'node_list:resource_group',
      ])
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
    return $this->buildResourceDetailResponse($node);
  }

  /**
   * GET /api/1.0/resources/node/{nid} — full resource detail by node ID.
   *
   * Same payload as getResource(), for resources with no CIDER resource ID.
   * Only published access_active_resources_from_cid nodes are served; any
   * other nid (missing, unpublished, other bundle) is a 404.
   */
  public function getResourceByNid(int|string $nid): JsonResponse {
    $nids = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('nid', (int) $nid)
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->range(0, 1)
      ->execute();

    if (empty($nids)) {
      return new JsonResponse(['error' => 'Resource not found'], 404);
    }

    $node = $this->entityTypeManager()->getStorage('node')->load(reset($nids));
    return $this->buildResourceDetailResponse($node);
  }

  /**
   * Build the full detail response for an already-loaded resource node.
   *
   * Shared by getResource() and getResourceByNid() so both return identical
   * responses for the same node.
   */
  private function buildResourceDetailResponse(NodeInterface $node): CacheableJsonResponse {
    $group = $this->inheritance->findParentGroup($node);
    // Computed from the unmodified node, before inheritance touches the clone.
    $listed = $this->isListed($node, $this->listedGroupMemberNids());

    // Apply Group-level inheritance to a clone so our in-memory mutations
    // don't leak into Drupal's static entity cache for downstream callers.
    $node = clone $node;
    $this->inheritance->applyInheritance($node);

    $changed = $node->getChangedTime();

    // Node types and queues are the source of truth for compute hardware.
    // queue_specs stays in the payload in its old per-row shape: derived from
    // them when the resource has node types, read from the old queue hardware
    // fields otherwise (before the migration has run for that resource).
    $nodeTypes = $this->getNodeTypeData($node);
    $queueRows = $this->getQueueRows($node);

    $data = [
      'nid' => (int) $node->id(),
      'title' => operations_cider_resource_display_name($node),
      'short_name' => $this->stringValue($node, 'field_cider_short_name'),
      'resource_id' => $node->get('field_cider_resource_id')->value,
      'global_resource_id' => $node->get('field_access_global_resource_id')->value,
      'resource_type' => $node->get('field_cider_resource_type')->value,
      'org_name' => $node->get('field_access_org_name')->value,
      'org_url' => $this->getLinkValue($node, 'field_access_org_url'),
      'status' => $node->get('field_cider_latest_status')->value,
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'last_modified' => date('c', $changed),
      'listed' => $listed,
      'description' => $node->get('field_rp_description')->value,
      'mfa_required' => (bool) $node->get('field_rp_mfa_required')->value,
      'account_required' => (bool) $node->get('field_rp_account_required')->value,
      'ondemand_url' => $this->getLinkValue($node, 'field_rp_ondemand_url'),
      'office_hours' => $this->getLinkValue($node, 'field_rp_office_hours'),
      'login_text' => $this->getScalarValue($node, 'field_rp_login_text'),
      'file_transfer_text' => $this->getScalarValue($node, 'field_rp_file_transfer_text'),
      'storage_text' => $this->getScalarValue($node, 'field_rp_storage_text'),
      'datasets_text' => $this->getScalarValue($node, 'field_rp_datasets_text'),
      'jobs_info' => $this->getScalarValue($node, 'field_rp_jobs_info'),
      'software_list_url' => $this->getLinkValue($node, 'field_rp_software_list_url'),
      'external_storage' => $this->getScalarValue($node, 'field_rp_external_storage'),
      'ssh_logins' => $this->getParagraphData($node, 'field_rp_ssh_login_nodes', self::SSH_LOGIN_MAP),
      'login_help_links' => $this->getMultiLinkValues($node, 'field_rp_login_help_links'),
      'account_setup_url' => $this->getLinkValue($node, 'field_rp_account_setup_url'),
      'support_links' => $this->getMultiLinkValues($node, 'field_rp_support_links'),
      'file_transfer' => $this->getParagraphData($node, 'field_rp_file_transfer', self::FILE_TRANSFER_MAP),
      'storage' => $this->getParagraphData($node, 'field_rp_storage', self::STORAGE_MAP),
      'queue_specs' => $nodeTypes
        ? $this->deriveQueueSpecs($queueRows, $nodeTypes)
        : $this->getParagraphData($node, 'field_rp_queue_specs', self::QUEUE_SPEC_MAP),
      'node_types' => $nodeTypes,
      'queues' => $this->buildQueues($queueRows),
      'datasets' => $this->getParagraphData($node, 'field_rp_datasets', self::DATASET_MAP),
    ];

    // Per-section documentation links. These are the twelve inheritable link
    // fields the page renders as a "Documentation" link on each section.
    // applyInheritance() has already run, so getLinkValue() returns a value the
    // group supplied when the resource itself has none.
    $data['documentation_links'] = [];
    foreach (self::DOCUMENTATION_LINK_FIELDS as $key => $field) {
      $data['documentation_links'][$key] = $this->getLinkValue($node, $field);
    }

    // Normalise and derive the wall-time pair for each queue spec. The field
    // stores whole minutes; for backwards compatibility we still expose
    // max_wall_time_hours (now possibly fractional, e.g. 0.5 for a 30-minute
    // queue) alongside a human-readable display string.
    foreach ($data['queue_specs'] as &$q) {
      $minutes = $q['max_wall_time_minutes'] ?? NULL;
      unset($q['max_wall_time_minutes']);
      $minutes = ($minutes === NULL || $minutes === '') ? NULL : (int) $minutes;
      $q['max_wall_time_hours'] = $minutes === NULL ? NULL : $minutes / 60;
      $q['max_wall_time_display'] = $this->wallTimeDisplay($minutes);
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
    foreach ($data['node_types'] as &$nodeType) {
      $nodeType['summary'] = $this->buildNodeTypeSummary($nodeType);
    }
    unset($nodeType);
    foreach ($data['storage'] as &$row) {
      $row['summary'] = $this->buildStorageSummary($row);
    }
    unset($row);

    // XDMoD metrics, keyed by queue name in their own object rather than
    // attached to each queue_specs row.
    //
    // The rows are one per queue-and-hardware pair while XDMoD measures per
    // queue, so a queue offered on two hardware configurations is several rows
    // sharing one measurement. Attaching per row repeats it: Bridges-2 GPU has
    // ten rows under three names, so the same figures would appear five times
    // and read as five independently-measured queues. QueueSpecGrouper exists
    // to collapse exactly that for the page; this keeps the API honest the
    // same way, without changing the shape of queue_specs that consumers
    // already read. Join on the row's `name`.
    //
    // Refreshed nightly and therefore excluded from the content hash — see
    // ResourceContentHash::STRIP_TOP_LEVEL. `queue_metrics_updated` is the
    // fetch date; `queue_metrics_last_data` is the latest day XDMoD actually
    // reported data for (NULL for legacy blobs or when there is none), so a
    // consumer can tell a fresh fetch of old data from current data.
    $metrics = $this->decodeQueueMetrics($node);
    $data['queue_metrics_updated'] = $metrics['updated'];
    $data['queue_metrics_last_data'] = $metrics['last_data'];
    // Cast so an empty map encodes as {} rather than []. PHP renders an empty
    // array as a JSON list, and most resources have no metrics at all, so
    // without this the common case contradicts the documented object type and
    // breaks a client generated from the spec.
    $data['queue_metrics'] = (object) $metrics['queues'];

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

    // `listed` depends on resource groups (membership, listing flag, status).
    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($node)
      ->addCacheTags(['node_list:resource_group']);
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
   *   capability menus. It reflects inherited values (each variant is resolved
   *   against its group) and counts a section only when the detail endpoint
   *   would return content for it. A section counts when it has paragraph rows
   *   or its prose field is non-blank: login also counts the OnDemand URL,
   *   login text and login help links; file transfer counts its text; storage
   *   counts its text and external storage; queue specs count node types and
   *   the jobs info text; datasets count their text.
   * - `variants`: list of member CIDER variants for traceability.
   */
  public function listResourceGroups() {
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $query = $node_storage
      ->getQuery()
      ->condition('type', 'resource_group')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('title');
    $group_nids = $query->execute();
    $groups = $node_storage->loadMultiple($group_nids);

    $cacheability = (new CacheableMetadata())->addCacheTags([
      'node_list:resource_group',
      'node_list:access_active_resources_from_cid',
    ]);

    $result = [];
    foreach ($groups as $group) {
      $cacheability->addCacheableDependency($group);
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
        $cacheability->addCacheableDependency($variant);
        $variants[] = [
          'nid' => (int) $variant->id(),
          'title' => operations_cider_resource_display_name($variant),
          'short_name' => $this->stringValue($variant, 'field_cider_short_name'),
          'resource_id' => $variant->get('field_cider_resource_id')->value,
          'global_resource_id' => $variant->get('field_access_global_resource_id')->value,
        ];

        // Resolve inheritance on a clone, as getResource() does, so the list
        // agrees with what the detail endpoint returns for this variant.
        $resolved = clone $variant;
        // The list resolves each variant against the group it is iterating,
        // while getResource() uses findParentGroup(). The two agree only while
        // a resource belongs to one group, which is the same single-group
        // assumption the detail endpoint makes (findParentGroup() logs a
        // warning otherwise).
        $this->inheritance->applyInheritance($resolved, $group);
        $populated = array_merge($populated, $this->populatedSections($resolved));
      }
      $populated = array_values(array_intersect(self::SECTION_ORDER, $populated));

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
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Whether a node is flagged for listing on the documentation index domain.
   *
   * True when field_rp_listing is set and field_domain_access includes
   * LISTED_DOMAIN_ID, matching views.view.rp_documentation_index.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A resource node. Groups are filtered the same way in SQL by
   *   listedGroupMemberNids().
   */
  private function isFlaggedListed(NodeInterface $node): bool {
    if (!$node->hasField('field_rp_listing') || !$node->hasField('field_domain_access')) {
      return FALSE;
    }
    if (!(bool) $node->get('field_rp_listing')->value) {
      return FALSE;
    }
    foreach ($node->get('field_domain_access') as $item) {
      if (($item->getValue()['target_id'] ?? NULL) === self::LISTED_DOMAIN_ID) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Nids of resources that are members of a listed, published resource group.
   *
   * One group query plus one loadMultiple, however many resources are asked
   * about afterwards.
   *
   * @return array<int, true>
   *   Set of member nids, keyed by nid.
   */
  private function listedGroupMemberNids(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    // Access checking is skipped on purpose. `listed` describes the
    // documentation index on the AMP domain, not what the current host can
    // see, and domain_access grants would otherwise hide AMP-only groups on
    // other hosts. Only a boolean about resources the caller can already see
    // is derived from these groups; the published filter is kept.
    $group_nids = $storage->getQuery()
      ->condition('type', 'resource_group')
      ->condition('status', 1)
      ->condition('field_rp_listing', 1)
      ->condition('field_domain_access', self::LISTED_DOMAIN_ID)
      ->accessCheck(FALSE)
      ->execute();

    $members = [];
    foreach ($storage->loadMultiple($group_nids) as $group) {
      if (!$group->hasField('field_cider_resources')) {
        continue;
      }
      foreach ($group->get('field_cider_resources') as $item) {
        $members[(int) ($item->getValue()['target_id'] ?? 0)] = TRUE;
      }
    }
    return $members;
  }

  /**
   * Whether a resource appears on the public documentation index.
   *
   * Listed on its own, or via membership in a listed group.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A resource node.
   * @param array<int, true> $listed_member_nids
   *   Result of listedGroupMemberNids().
   */
  private function isListed(NodeInterface $node, array $listed_member_nids): bool {
    return $this->isFlaggedListed($node) || isset($listed_member_nids[(int) $node->id()]);
  }

  /**
   * List the sections for which the detail endpoint would return content.
   *
   * Expects a node that already had inheritance applied. Uses the same
   * extractors as getResource(), so a paragraph with a missing revision or only
   * empty fields does not count. Prose fields count when non-blank, the way
   * login text does. Queue specs also count field_rp_jobs_info because the node
   * template renders the jobs section when queue_specs or jobs_info is present,
   * so the list matches the page.
   *
   * @return string[]
   *   Section ids in SECTION_ORDER order.
   */
  private function populatedSections(NodeInterface $node): array {
    $has_rows = fn(string $field, array $map): bool =>
      $node->hasField($field) && $this->getParagraphData($node, $field, $map) !== [];

    $has_text = fn(string $field): bool =>
      trim((string) $this->getScalarValue($node, $field)) !== '';

    $login = $has_rows('field_rp_ssh_login_nodes', self::SSH_LOGIN_MAP)
      || ($node->hasField('field_rp_ondemand_url') && !$node->get('field_rp_ondemand_url')->isEmpty())
      || $has_text('field_rp_login_text')
      || $this->getMultiLinkValues($node, 'field_rp_login_help_links') !== [];

    $top_software = FALSE;
    if ($node->hasField('field_rp_top_software')) {
      $decoded = json_decode((string) $node->get('field_rp_top_software')->value, TRUE);
      $top_software = is_array($decoded) && $decoded !== [];
    }

    $file_transfer = $has_rows('field_rp_file_transfer', self::FILE_TRANSFER_MAP)
      || $has_text('field_rp_file_transfer_text');
    $storage = $has_rows('field_rp_storage', self::STORAGE_MAP)
      || $has_text('field_rp_storage_text')
      || $has_text('field_rp_external_storage');
    // A resource can document node types without queues (cloud resources);
    // that is still compute hardware, so it counts as the queue_specs section.
    // getQueueRows() also catches a queue whose only content is its node type
    // entries, which the legacy QUEUE_SPEC_MAP row would miss.
    $queue_specs = $has_rows('field_rp_queue_specs', self::QUEUE_SPEC_MAP)
      || $this->getQueueRows($node) !== []
      || $this->getNodeTypeData($node) !== []
      || $has_text('field_rp_jobs_info');
    $datasets = $has_rows('field_rp_datasets', self::DATASET_MAP)
      || $has_text('field_rp_datasets_text');

    $flags = [
      'login' => $login,
      'file_transfer' => $file_transfer,
      'storage' => $storage,
      'queue_specs' => $queue_specs,
      'top_software' => $top_software,
      'datasets' => $datasets,
    ];
    return array_keys(array_filter($flags));
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
   * Decode field_rp_queue_metrics into its dates and per-queue map.
   *
   * Returns nulls and an empty map for missing, empty, or malformed JSON, so a
   * bad blob degrades to "no metrics" rather than breaking the payload.
   * `last_data` is only passed through when it is a strict YYYY-MM-DD date;
   * legacy blobs without the key yield NULL.
   *
   * @return array{updated: string|null, last_data: string|null, queues: array}
   *   The fetch date, the latest data date, and the per-queue map.
   */
  private function decodeQueueMetrics($node): array {
    if (!$node->hasField('field_rp_queue_metrics')) {
      return ['updated' => NULL, 'last_data' => NULL, 'queues' => []];
    }
    $raw = $node->get('field_rp_queue_metrics')->value;
    $decoded = $raw ? json_decode($raw, TRUE) : NULL;
    if (!is_array($decoded)) {
      return ['updated' => NULL, 'last_data' => NULL, 'queues' => []];
    }
    $queues = $decoded['queues'] ?? NULL;
    return [
      'updated' => $decoded['updated'] ?? NULL,
      'last_data' => $this->validDate($decoded['last_data'] ?? NULL),
      'queues' => is_array($queues) ? $queues : [],
    ];
  }

  /**
   * Return the value only if it is a real YYYY-MM-DD date, else NULL.
   */
  private function validDate(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : NULL;
  }

  /**
   * Extract a single link field value.
   */
  private function getLinkValue($node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = $node->get($field_name)->first();
    if (!$value) {
      return NULL;
    }
    try {
      return $value->getUrl()->toString();
    }
    catch (\Exception $e) {
      // Url::fromUri() throws on a malformed or scheme-less stored URI. The
      // widget validates on save, so this only bites legacy or imported rows —
      // but there are now twelve more call sites, and one bad row must not 500
      // the whole resource. Mirrors getMultiLinkValues()'s fallback.
      return $value->uri ?: NULL;
    }
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
    foreach ($this->loadParagraphs($node, $field_name) as $paragraph) {
      $row = $this->paragraphRow($paragraph, $field_map);
      if ($this->rowHasValue($row)) {
        $items[] = $row;
      }
    }
    return $items;
  }

  /**
   * Load the referenced paragraph revisions of a field, in order.
   *
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   *   A list, with references that no longer load left out.
   */
  private function loadParagraphs($entity, string $field_name): array {
    if (!$entity->hasField($field_name)) {
      return [];
    }
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $paragraph_storage */
    $paragraph_storage = $this->entityTypeManager()->getStorage('paragraph');
    $paragraphs = [];
    foreach ($entity->get($field_name) as $ref) {
      $paragraph = $paragraph_storage->loadRevision($ref->target_revision_id);
      if ($paragraph) {
        $paragraphs[] = $paragraph;
      }
    }
    return $paragraphs;
  }

  /**
   * Read a paragraph's fields into a flat row keyed by the given map.
   */
  private function paragraphRow($paragraph, array $field_map): array {
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
          if ($field->isEmpty()) {
            $row[$key] = NULL;
          }
          else {
            try {
              $row[$key] = $field->first()->getUrl()->toString();
            }
            catch (\Exception $e) {
              // Url::fromUri() throws on a malformed or scheme-less stored
              // URI; one bad row must not 500 the whole response. Mirrors
              // getLinkValue()'s fallback to the raw URI.
              $row[$key] = $field->first()->uri ?: NULL;
            }
          }
        }
        else {
          $row[$key] = $field->value;
        }
      }
    }
    return $row;
  }

  /**
   * Whether a row has any value other than NULL or an empty string.
   */
  private function rowHasValue(array $row): bool {
    return (bool) array_filter($row, fn($v) => $v !== NULL && $v !== '');
  }

  /**
   * Read the resource's node types, each with its GPU list.
   *
   * @return list<array<string, mixed>>
   *   Node types in editor order; empty when the resource has none.
   */
  private function getNodeTypeData($node): array {
    $nodeTypes = [];
    foreach ($this->loadParagraphs($node, 'field_rp_node_types') as $paragraph) {
      $row = $this->paragraphRow($paragraph, [
        'field_rp_node_type_label' => 'label',
        'field_rp_node_type_description' => 'description',
        'field_rp_node_count' => 'node_count',
        'field_rp_cpus' => 'cpu_type',
        'field_rp_cpu_count' => 'cpu_count',
        'field_rp_vram' => 'ram',
      ]);
      $gpus = [];
      foreach ($this->loadParagraphs($paragraph, 'field_rp_node_gpus') as $gpuParagraph) {
        $gpu = $this->paragraphRow($gpuParagraph, [
          'field_rp_gpus' => 'type',
          'field_rp_gpu_count' => 'count',
          'field_rp_gpu_vram' => 'vram',
        ]);
        if ($this->rowHasValue($gpu)) {
          $gpus[] = $gpu;
        }
      }
      $row['gpus'] = $gpus;
      if (!$this->rowHasValue(array_diff_key($row, ['gpus' => 1])) && !$gpus) {
        continue;
      }
      $nodeTypes[] = [
        'label' => $this->trimmedOrNull($row['label'] ?? NULL),
        'description' => $row['description'] ?? NULL,
        'node_count' => $row['node_count'] ?? NULL,
        'cpu_type' => $row['cpu_type'] ?? NULL,
        'cpu_count' => $row['cpu_count'] ?? NULL,
        'ram' => $row['ram'] ?? NULL,
        'gpus' => $gpus,
      ];
    }
    return $nodeTypes;
  }

  /**
   * Read the queue paragraphs with the node types each one points at.
   *
   * Internal shape: the wall time stays in minutes so deriveQueueSpecs() can
   * hand rows to the same wall-time normalizer the legacy rows use.
   *
   * @return list<array{name: mixed, purpose: mixed, max_wall_time_minutes: mixed, nodes: list<array{node_type: ?string, node_count: ?int}>}>
   *   Queues in editor order.
   */
  private function getQueueRows($node): array {
    $queues = [];
    foreach ($this->loadParagraphs($node, 'field_rp_queue_specs') as $paragraph) {
      $row = $this->paragraphRow($paragraph, [
        'field_rp_queue_name' => 'name',
        'field_rp_queue_purpose' => 'purpose',
        'field_rp_max_wall_time' => 'max_wall_time_minutes',
      ]);
      $nodes = [];
      foreach ($this->loadParagraphs($paragraph, 'field_rp_queue_nodes') as $nodeParagraph) {
        $entry = $this->paragraphRow($nodeParagraph, [
          'field_rp_queue_node_type' => 'node_type',
          'field_rp_node_count' => 'node_count',
        ]);
        if ($this->rowHasValue($entry)) {
          $nodes[] = [
            'node_type' => $this->trimmedOrNull($entry['node_type'] ?? NULL),
            'node_count' => $entry['node_count'] ?? NULL,
          ];
        }
      }
      if (!$this->rowHasValue($row) && !$nodes) {
        continue;
      }
      $row['nodes'] = $nodes;
      $queues[] = $row;
    }
    return $queues;
  }

  /**
   * Trim a label with NodeTypeReferences::normalize(); blank becomes NULL.
   */
  private function trimmedOrNull(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $value = NodeTypeReferences::normalize((string) $value);
    return $value === '' ? NULL : $value;
  }

  /**
   * Shape queue rows for the `queues` payload key.
   *
   * @return list<array<string, mixed>>
   *   Always a list; a resource with no node types gets queues with empty
   *   `nodes`.
   */
  private function buildQueues(array $queueRows): array {
    $queues = [];
    foreach ($queueRows as $row) {
      $minutes = $this->minutesOrNull($row['max_wall_time_minutes'] ?? NULL);
      $queues[] = [
        'name' => $row['name'] ?? NULL,
        'purpose' => $row['purpose'] ?? NULL,
        'max_wall_time_hours' => $minutes === NULL ? NULL : $minutes / 60,
        'max_wall_time_display' => $this->wallTimeDisplay($minutes),
        'nodes' => $row['nodes'],
      ];
    }
    return $queues;
  }

  /**
   * Derive legacy per-row queue_specs from node types and queues.
   *
   * One row per queue-and-node-type entry, in the keys the old rows had; the
   * wall time and summary are added by the caller's shared loops. A queue with
   * no node types gives one row with null hardware.
   *
   * @return list<array<string, mixed>>
   *   Rows in the legacy shape.
   */
  private function deriveQueueSpecs(array $queueRows, array $nodeTypes): array {
    $byLabel = [];
    foreach ($nodeTypes as $nodeType) {
      if ($nodeType['label'] !== NULL && !isset($byLabel[$nodeType['label']])) {
        $byLabel[$nodeType['label']] = $nodeType;
      }
    }

    $rows = [];
    foreach ($queueRows as $queue) {
      $entries = $queue['nodes'] ?: [['node_type' => NULL, 'node_count' => NULL]];
      foreach ($entries as $entry) {
        $nodeType = $entry['node_type'] !== NULL ? ($byLabel[$entry['node_type']] ?? NULL) : NULL;
        $gpus = $nodeType['gpus'] ?? [];
        $types = array_values(array_filter(array_map(fn($g) => $g['type'] ?? NULL, $gpus), fn($t) => $t !== NULL && $t !== ''));
        $counts = array_filter(array_map(fn($g) => $g['count'] ?? NULL, $gpus), fn($c) => $c !== NULL);
        $purpose = $queue['purpose'] ?? NULL;
        if ($purpose === NULL || $purpose === '') {
          $purpose = $nodeType['description'] ?? NULL;
        }
        $rows[] = [
          'name' => $queue['name'] ?? NULL,
          'purpose' => $purpose,
          'node_count' => $entry['node_count'],
          'cpu_count' => $nodeType['cpu_count'] ?? NULL,
          'cpu_type' => $nodeType['cpu_type'] ?? NULL,
          // Several GPU models on one node: join the models, sum the counts
          // that are known, and drop the vRAM, which can differ per model.
          'gpu_count' => $counts ? array_sum($counts) : NULL,
          'gpu_type' => $types ? implode(', ', $types) : NULL,
          'gpu_vram' => count($gpus) === 1 ? ($gpus[0]['vram'] ?? NULL) : NULL,
          'ram' => $nodeType['ram'] ?? NULL,
          'max_wall_time_minutes' => $queue['max_wall_time_minutes'] ?? NULL,
        ];
      }
    }
    return $rows;
  }

  /**
   * Whole minutes from a stored wall time, or NULL when blank.
   */
  private function minutesOrNull(mixed $minutes): ?int {
    return ($minutes === NULL || $minutes === '') ? NULL : (int) $minutes;
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

    // CIDER stores the text "None" for CPU-only queues; treat it as empty.
    if (is_string($queue['gpu_type'] ?? NULL) && strcasecmp(trim($queue['gpu_type']), 'None') === 0) {
      $queue['gpu_type'] = '';
    }

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
      $nodes = (int) $queue['node_count'];
      $parts[] = $nodes . ($nodes === 1 ? ' node' : ' nodes');
    }

    $parts = array_merge($parts, $this->cpuRamSummaryParts($queue));

    return $parts ? implode(', ', $parts) : NULL;
  }

  /**
   * Build a one-line hardware summary of a node type.
   *
   * Lists every GPU model, e.g. "4× AMD MI100 (32 GB vRAM), 4× AMD MI210,
   * 64-core AMD EPYC 7763, 256 GB RAM". The node count is left out: it is
   * how many nodes of this type exist, not part of the hardware.
   */
  private function buildNodeTypeSummary(array $nodeType): ?string {
    $parts = [];
    foreach ($nodeType['gpus'] as $gpu) {
      $text = '';
      if (!empty($gpu['count'])) {
        $text = $gpu['count'] . '×';
      }
      if (!empty($gpu['type'])) {
        $text = $text === '' ? $gpu['type'] : $text . ' ' . $gpu['type'];
      }
      if (!empty($gpu['vram'])) {
        $text .= ' (' . $gpu['vram'] . ' GB vRAM)';
      }
      if (trim($text) !== '') {
        $parts[] = trim($text);
      }
    }
    $parts = array_merge($parts, $this->cpuRamSummaryParts($nodeType));
    return $parts ? implode(', ', $parts) : NULL;
  }

  /**
   * The CPU and RAM phrases shared by queue and node type summaries.
   *
   * @return string[]
   *   Zero, one or two phrases.
   */
  private function cpuRamSummaryParts(array $hardware): array {
    $parts = [];
    if (!empty($hardware['cpu_count']) && !empty($hardware['cpu_type'])) {
      $parts[] = $hardware['cpu_count'] . '-core ' . $hardware['cpu_type'];
    }
    elseif (!empty($hardware['cpu_type'])) {
      $parts[] = $hardware['cpu_type'];
    }
    elseif (!empty($hardware['cpu_count'])) {
      $parts[] = $hardware['cpu_count'] . '-core';
    }

    // field_rp_vram holds node RAM (GB), despite its legacy field name.
    if (isset($hardware['ram']) && $hardware['ram'] !== NULL && $hardware['ram'] !== '') {
      $parts[] = $this->formatGbSize((int) $hardware['ram']) . ' RAM';
    }
    return $parts;
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
   * Human-readable duration for a wall-time limit stored in whole minutes.
   *
   * E.g. 30 => "30 minutes", 2880 => "48 hours", 90 => "1 hour 30 minutes".
   */
  private function wallTimeDisplay(?int $minutes): ?string {
    if ($minutes === NULL || $minutes <= 0) {
      return NULL;
    }
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    $parts = [];
    if ($hours > 0) {
      $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
    }
    if ($mins > 0) {
      $parts[] = $mins . ' ' . ($mins === 1 ? 'minute' : 'minutes');
    }
    return implode(' ', $parts);
  }

}
