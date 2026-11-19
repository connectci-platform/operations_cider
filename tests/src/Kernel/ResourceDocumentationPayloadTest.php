<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\operations_cider\Controller\ResourceDocumentationController;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\operations_cider\Traits\NodeTypeFieldsTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Covers the documentation links and queue metrics on the resource detail API.
 *
 * Both are read from fields the page already renders but the payload did not
 * carry. The links are inheritable, so the interesting case is a group value
 * reaching a resource that leaves the field empty. The metrics are keyed by
 * queue name in a JSON blob, so the interesting case is a queue with no entry.
 *
 * @group operations_cider
 */
class ResourceDocumentationPayloadTest extends KernelTestBase {

  use NodeTypeFieldsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'link', 'key', 'domain', 'file',
    'options', 'entity_reference_revisions', 'paragraphs', 'operations_cider',
  ];

  /**
   * Three link fields the GROUP supplies and the resource leaves empty.
   */
  private const INHERITED_FIELDS = [
    'field_rp_ssh_login_link' => 'ssh_login',
    'field_rp_storage_link' => 'storage',
    'field_rp_datasets_link' => 'datasets',
  ];

  /**
   * One link field the RESOURCE supplies itself.
   */
  private const OWN_FIELD = 'field_rp_software_link';
  private const OWN_KEY = 'software';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system']);

    \Drupal::entityTypeManager()->getStorage('domain')->create([
      'id' => 'amp_cyberinfrastructure_org',
      'hostname' => 'amp_cyberinfrastructure_org.example.com',
      'name' => 'amp_cyberinfrastructure_org',
      'weight' => 0,
    ])->save();

    $resource = 'access_active_resources_from_cid';
    NodeType::create(['type' => $resource, 'name' => 'Resource'])->save();
    NodeType::create(['type' => 'resource_group', 'name' => 'Resource Group'])->save();
    $both = [$resource, 'resource_group'];

    $this->addField('field_cider_resources', 'entity_reference', ['resource_group'], [
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ]);

    $linkFields = array_merge(array_keys(self::INHERITED_FIELDS), [self::OWN_FIELD]);
    foreach ($linkFields as $name) {
      $this->addField($name, 'link', $both);
    }

    // getResource() looks a resource up by this field, so the endpoint test
    // needs it even though the link and metrics logic does not.
    $this->addField('field_cider_resource_id', 'string', [$resource]);

    // getResource() checks whether the resource is listed, which queries these
    // fields on both bundles.
    $this->addField('field_rp_listing', 'boolean', $both);
    $this->addField('field_domain_access', 'entity_reference', $both, [
      'cardinality' => -1,
      'settings' => ['target_type' => 'domain'],
    ]);

    // getResource() reads these directly (no hasField() guard), so they must
    // exist for the endpoint test; their values are irrelevant here.
    $endpointFields = [
      'string' => [
        'field_access_global_resource_id',
        'field_cider_resource_type',
        'field_access_org_name',
        'field_cider_latest_status',
      ],
      'text_long' => ['field_rp_description'],
      'boolean' => ['field_rp_mfa_required', 'field_rp_account_required'],
      'string_long' => [
        'field_rp_top_software',
        'field_rp_ood_software',
        'field_rp_queue_metrics',
      ],
    ];
    foreach ($endpointFields as $type => $names) {
      foreach ($names as $name) {
        $this->addField($name, $type, [$resource]);
      }
    }

    // getParagraphData() loads from the paragraph storage, so the module is
    // enabled and the reference fields exist.
    $paragraphFields = [
      'field_rp_ssh_login_nodes',
      'field_rp_file_transfer',
      'field_rp_storage',
      'field_rp_datasets',
    ];
    foreach ($paragraphFields as $name) {
      $this->addField($name, 'entity_reference_revisions', [$resource], [
        'cardinality' => -1,
        'settings' => ['target_type' => 'paragraph'],
      ]);
    }

    // Queue and node type paragraph fields (field_rp_queue_specs included),
    // then the rest of what the detail and group-list endpoints read
    // unguarded, so the tests below can fetch a whole payload.
    $this->setUpNodeTypeFields();
    $this->addPayloadFields();

    // The endpoint is called directly, but accessCheck(TRUE) queries still run
    // as the current user, so give it the permission.
    Role::create(['id' => 'viewer', 'label' => 'Viewer'])
      ->grantPermission('access content')
      ->save();
    $user = User::create(['name' => 'viewer', 'status' => 1, 'roles' => ['viewer']]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Add one field storage and its per-bundle configs.
   */
  private function addField(string $name, string $type, array $bundles, array $storage = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
    ] + $storage)->save();
    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => $bundle,
      ])->save();
    }
  }

  /**
   * Add the fields the detail and group-list endpoints read unguarded.
   *
   * Skips any that setUp() already created.
   */
  private function addPayloadFields(): void {
    $resource = ['access_active_resources_from_cid'];
    $both = ['access_active_resources_from_cid', 'resource_group'];
    $simple = [
      'string' => [
        'field_access_global_resource_id', 'field_access_org_name',
        'field_cider_latest_status', 'field_cider_resource_type',
        'field_cider_short_name',
      ],
      'text_long' => ['field_rp_description'],
      'boolean' => ['field_rp_mfa_required', 'field_rp_account_required'],
      'string_long' => [
        'field_rp_login_text', 'field_rp_file_transfer_text',
        'field_rp_storage_text', 'field_rp_datasets_text', 'field_rp_jobs_info',
        'field_rp_external_storage', 'field_rp_top_software',
        'field_rp_ood_software',
      ],
      'link' => [
        'field_access_org_url', 'field_rp_ondemand_url', 'field_rp_office_hours',
        'field_rp_software_list_url', 'field_rp_account_setup_url',
      ],
    ];
    foreach ($simple as $type => $names) {
      foreach ($names as $name) {
        $this->ensureNodeField($name, $type, $resource);
      }
    }
    $this->ensureNodeField('field_rp_listing', 'boolean', $both);
    $this->ensureNodeField('field_domain_access', 'string', $both, ['cardinality' => -1]);
    foreach (['field_rp_login_help_links', 'field_rp_support_links'] as $name) {
      $this->ensureNodeField($name, 'link', $resource, ['cardinality' => -1]);
    }
    foreach ([
      'field_rp_ssh_login_nodes', 'field_rp_file_transfer', 'field_rp_storage',
      'field_rp_datasets',
    ] as $name) {
      $this->ensureNodeField($name, 'entity_reference_revisions', $resource, [
        'cardinality' => -1,
        'settings' => ['target_type' => 'paragraph'],
      ]);
    }
  }

  /**
   * Create a node field storage and its instances when missing.
   */
  private function ensureNodeField(string $name, string $type, array $bundles, array $storage = []): void {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
      ] + $storage)->save();
    }
    foreach ($bundles as $bundle) {
      if (!FieldConfig::loadByName('node', $bundle, $name)) {
        FieldConfig::create([
          'field_name' => $name,
          'entity_type' => 'node',
          'bundle' => $bundle,
        ])->save();
      }
    }
  }

  /**
   * A resource in a group: the group's links come through, its own is kept.
   */
  public function testDocumentationLinksIncludeInheritedAndOwn(): void {
    $resource = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Test Resource',
      'status' => 1,
      self::OWN_FIELD => ['uri' => 'https://example.edu/own-software', 'title' => ''],
    ]);
    $resource->save();

    $groupValues = [
      'type' => 'resource_group',
      'title' => 'Test Group',
      'status' => 1,
      'field_cider_resources' => [['target_id' => $resource->id()]],
    ];
    foreach (array_keys(self::INHERITED_FIELDS) as $i => $field) {
      $groupValues[$field] = ['uri' => 'https://example.edu/group-' . $i, 'title' => ''];
    }
    Node::create($groupValues)->save();

    $links = $this->documentationLinksFor($resource);

    foreach (array_values(self::INHERITED_FIELDS) as $i => $key) {
      $this->assertSame(
        'https://example.edu/group-' . $i,
        $links[$key],
        "Section $key should inherit the group's documentation link."
      );
    }
    $this->assertSame(
      'https://example.edu/own-software',
      $links[self::OWN_KEY],
      "The resource's own link must not be overwritten by inheritance."
    );
  }

  /**
   * Every section key is present, with null where no link is set anywhere.
   */
  public function testUnsetSectionsAreNullRatherThanAbsent(): void {
    $resource = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Bare Resource',
      'status' => 1,
    ]);
    $resource->save();

    $links = $this->documentationLinksFor($resource);

    $this->assertArrayHasKey('login', $links);
    $this->assertNull($links['login']);
    $this->assertArrayHasKey(self::OWN_KEY, $links);
    $this->assertNull($links[self::OWN_KEY]);
  }

  /**
   * The metrics object is keyed by queue name and carries each queue once.
   *
   * Deliberately not attached to queue_specs rows: those are per
   * queue-and-hardware pair, so a queue on several configurations would repeat
   * one measurement and read as several queues.
   */
  public function testQueueMetricsAreKeyedByQueueName(): void {
    $resource = $this->makeResourceWithMetrics([
      'updated' => '2026-09-21',
      'queues' => [
        'GPU' => ['job_count' => 1250, 'wait_time' => 2.3],
        'GPU-shared' => ['job_count' => 380, 'wait_time' => 0.7],
      ],
    ]);

    $decoded = $this->decodeMetrics($resource);

    $this->assertSame(['GPU', 'GPU-shared'], array_keys($decoded['queues']));
    $this->assertSame(1250, $decoded['queues']['GPU']['job_count']);
    $this->assertSame('2026-09-21', $decoded['updated']);
  }

  /**
   * Malformed or missing metrics JSON degrades to no metrics, never an error.
   */
  public function testMalformedMetricsDegradeQuietly(): void {
    foreach (['', 'not json at all', '[]', '{"queues": "a string"}'] as $raw) {
      $resource = Node::create([
        'type' => 'access_active_resources_from_cid',
        'title' => 'Bad Metrics',
        'status' => 1,
        'field_rp_queue_metrics' => $raw,
      ]);
      $resource->save();

      $decoded = $this->decodeMetrics($resource);
      $this->assertSame([], $decoded['queues'], "Raw value '$raw' should yield no queues.");
      $this->assertNull($decoded['updated'], "Raw value '$raw' should yield no updated date.");
    }
  }

  /**
   * A valid last_data passes through; updated stays the fetch date.
   */
  public function testLastDataIsDecodedSeparatelyFromUpdated(): void {
    $resource = $this->makeResourceWithMetrics([
      'updated' => '2026-11-10',
      'last_data' => '2026-10-19',
      'queues' => [],
    ]);
    $decoded = $this->decodeMetrics($resource);
    $this->assertSame('2026-10-19', $decoded['last_data']);
    $this->assertSame('2026-11-10', $decoded['updated']);
  }

  /**
   * Legacy blobs and malformed last_data values decode to NULL.
   */
  public function testMissingOrMalformedLastDataIsNull(): void {
    $blobs = [
      'legacy' => ['updated' => '2026-11-10', 'queues' => []],
      'text' => ['updated' => '2026-11-10', 'last_data' => '2 hours ago', 'queues' => []],
      'int' => ['updated' => '2026-11-10', 'last_data' => 123, 'queues' => []],
      'impossible' => ['updated' => '2026-11-10', 'last_data' => '2026-13-40', 'queues' => []],
    ];
    foreach ($blobs as $label => $blob) {
      $decoded = $this->decodeMetrics($this->makeResourceWithMetrics($blob));
      $this->assertNull($decoded['last_data'], "Case '$label' should yield NULL.");
      $this->assertSame('2026-11-10', $decoded['updated']);
    }
  }

  /**
   * The endpoint keeps a null wait_time as JSON null, not dropping it or 0.
   */
  public function testNullWaitTimeEncodesAsJsonNull(): void {
    $resource = $this->makeResourceWithMetrics([
      'updated' => '2026-11-10',
      'last_data' => NULL,
      'queues' => ['wholenode' => ['wait_time' => NULL, 'job_count' => 5]],
    ]);
    $resource->set('field_cider_resource_id', 'metrics.example')->save();

    $controller = ResourceDocumentationController::create($this->container);
    $response = $controller->getResource('metrics.example');
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode($response->getContent(), TRUE);

    $this->assertArrayHasKey('wholenode', $payload['queue_metrics']);
    $queue = $payload['queue_metrics']['wholenode'];
    $this->assertArrayHasKey('wait_time', $queue);
    $this->assertNull($queue['wait_time']);
    $this->assertSame(5, $queue['job_count']);
  }

  /**
   * The text "None" for gpu_type is dropped; a single node is singular.
   */
  public function testQueueSummaryHandlesNoneGpuAndSingularNode(): void {
    $this->assertSame('2 nodes', $this->summary(['gpu_type' => 'None', 'node_count' => 2]));
    $this->assertSame('2 nodes', $this->summary(['gpu_type' => ' none ', 'node_count' => 2]));
    $gpu = ['gpu_count' => 2, 'gpu_type' => 'None', 'gpu_vram' => 80, 'node_count' => 1];
    $this->assertSame('2 (80 GB vRAM), 1 node', $this->summary($gpu));
    $one = $this->summary(['node_count' => 1]);
    $this->assertStringContainsString('1 node', $one);
    $this->assertStringNotContainsString('1 nodes', $one);
    $a100 = ['gpu_count' => 4, 'gpu_type' => 'NVIDIA A100', 'node_count' => 2];
    $this->assertSame('4 NVIDIA A100, 2 nodes', $this->summary($a100));
  }

  /**
   * Call the controller's own queue summary builder.
   */
  private function summary(array $queue): ?string {
    $controller = \Drupal::classResolver(
      'Drupal\operations_cider\Controller\ResourceDocumentationController'
    );
    $method = new \ReflectionMethod($controller, 'buildQueueSummary');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $queue);
  }

  /**
   * Node types and queues are emitted in their own shapes and key order.
   */
  public function testNodeTypesAndQueuesInPayload(): void {
    $gpuNode = $this->nodeType('GPU node', [
      'field_rp_node_type_description' => 'Eight-GPU nodes',
      'field_rp_node_count' => 12,
      'field_rp_cpus' => 'AMD EPYC 7763',
      'field_rp_cpu_architecture' => 'x86_64',
      'field_rp_cpu_count' => 64,
      'field_rp_node_ram' => 512,
    ], [
      ['gpu', 'AMD MI100', 4, 32],
      ['gpu', 'AMD MI210', 4, 64],
    ], [['gpu', 10], ['2', 2]]);
    $cpuNode = $this->nodeType('CPU node', [
      'field_rp_cpus' => 'AMD EPYC 7742',
      'field_rp_cpu_count' => 128,
      'field_rp_node_ram' => 2000,
    ], [], [[' 2 ', 700], ['1', NULL]]);
    // Stale hardware on the queue row must be ignored once node types exist.
    $gpuQueue = $this->queue('gpu', 'GPU jobs', 2880, [
      'field_rp_charged_per' => 'gpu',
      'field_rp_queue_access' => 'batch',
      'field_rp_node_count' => 999,
      'field_rp_gpus' => 'Stale GPU',
    ]);
    // Numeric-looking names must keep their order and stay a list.
    $second = $this->queue('2', NULL, 30);
    $first = $this->queue('1', NULL, NULL, ['field_rp_queue_access' => 'by_request']);
    $node = $this->resource([$gpuNode, $cpuNode], [$gpuQueue, $second, $first]);

    $payload = $this->payload($node);

    $keys = array_keys($payload);
    $at = array_search('queue_specs', $keys, TRUE);
    $this->assertSame(['node_types', 'queues'], array_slice($keys, $at + 1, 2));

    $this->assertTrue(array_is_list($payload['node_types']));
    $this->assertSame(['GPU node', 'CPU node'], array_column($payload['node_types'], 'label'));
    $this->assertSame([
      'label' => 'GPU node',
      'description' => 'Eight-GPU nodes',
      'node_count' => 12,
      'cpu_type' => 'AMD EPYC 7763',
      'cpu_architecture' => 'x86_64',
      'cpu_count' => 64,
      'ram' => 512,
      'accelerators' => [
        ['type' => 'gpu', 'model' => 'AMD MI100', 'count' => 4, 'memory' => 32],
        ['type' => 'gpu', 'model' => 'AMD MI210', 'count' => 4, 'memory' => 64],
      ],
      'summary' => '4× AMD MI100 (32 GB vRAM), 4× AMD MI210 (64 GB vRAM), 64-core AMD EPYC 7763, 512 GB RAM',
    ], $payload['node_types'][0]);
    $this->assertArrayNotHasKey('gpus', $payload['node_types'][0]);
    $this->assertNull($payload['node_types'][1]['cpu_architecture']);
    $this->assertSame([], $payload['node_types'][1]['accelerators']);
    $this->assertSame('128-core AMD EPYC 7742, 2 TB RAM', $payload['node_types'][1]['summary']);

    $this->assertTrue(array_is_list($payload['queues']));
    $this->assertSame(['gpu', '2', '1'], array_column($payload['queues'], 'name'));
    $this->assertSame([
      'name' => 'gpu',
      'purpose' => 'GPU jobs',
      'max_wall_time_hours' => 48,
      'max_wall_time_display' => '48 hours',
      'charged_per' => 'gpu',
      'access' => 'batch',
      'nodes' => [['node_type' => 'GPU node', 'node_count' => 10]],
    ], $payload['queues'][0]);
    $this->assertSame(0.5, $payload['queues'][1]['max_wall_time_hours']);
    $this->assertSame('30 minutes', $payload['queues'][1]['max_wall_time_display']);
    $this->assertNull($payload['queues'][1]['charged_per']);
    $this->assertNull($payload['queues'][1]['access']);
    $this->assertSame('by_request', $payload['queues'][2]['access']);
    // Nodes come in node type order; names are matched after trimming.
    $this->assertSame([
      ['node_type' => 'GPU node', 'node_count' => 2],
      ['node_type' => 'CPU node', 'node_count' => 700],
    ], $payload['queues'][1]['nodes']);
    $this->assertSame([['node_type' => 'CPU node', 'node_count' => NULL]], $payload['queues'][2]['nodes']);
    $this->assertNull($payload['queues'][2]['max_wall_time_hours']);

    // One derived queue_specs row per node type listing the queue.
    $this->assertTrue(array_is_list($payload['queue_specs']));
    $this->assertSame(
      ['gpu', '2', '2', '1'],
      array_column($payload['queue_specs'], 'name')
    );
    $this->assertSame(10, $payload['queue_specs'][0]['node_count']);
    $this->assertSame('AMD MI100, AMD MI210', $payload['queue_specs'][0]['gpu_type']);
    $this->assertSame(64, $payload['queue_specs'][0]['cpu_count']);
    $this->assertSame(2880 / 60, $payload['queue_specs'][0]['max_wall_time_hours']);
    $this->assertNull($payload['queue_specs'][2]['purpose'], 'The queue purpose is never filled from the node type description.');
    $this->assertSame('GPU jobs', $payload['queue_specs'][0]['purpose']);
    $this->assertSame(
      ['name', 'purpose', 'node_count', 'cpu_count', 'cpu_type', 'gpu_count', 'gpu_type', 'gpu_vram', 'ram', 'max_wall_time_hours', 'max_wall_time_display', 'summary'],
      array_keys($payload['queue_specs'][0])
    );
    $this->assertSame(
      '8 AMD MI100, AMD MI210, 10 nodes, 64-core AMD EPYC 7763, 512 GB RAM',
      $payload['queue_specs'][0]['summary']
    );
  }

  /**
   * Derived queue_specs read GPU accelerators only.
   */
  public function testDerivedQueueSpecsUseGpuAcceleratorsOnly(): void {
    $fpga = $this->nodeType('FPGA node', ['field_rp_node_ram' => 64], [
      ['fpga', 'Bittware IA-840F', 2, 64],
    ], [['fpga-q', 2]]);
    $mixedFpga = $this->nodeType('GPU and FPGA', [], [
      ['fpga', 'Bittware IA-840F', 1, 64],
      ['gpu', 'NVIDIA A100', 4, 80],
    ], [['fpga-q', 1]]);
    $queue = $this->queue('fpga-q', 'Own purpose', 60);
    $payload = $this->payload($this->resource([$fpga, $mixedFpga], [$queue]));

    [$a, $b] = $payload['queue_specs'];
    $this->assertNull($a['gpu_type']);
    $this->assertNull($a['gpu_count']);
    $this->assertNull($a['gpu_vram']);
    $this->assertSame(64, $a['ram']);
    $this->assertSame('Own purpose', $a['purpose']);
    $this->assertSame('NVIDIA A100', $b['gpu_type']);
    $this->assertSame(4, $b['gpu_count']);
    $this->assertSame(80, $b['gpu_vram']);

    // The node type keeps every accelerator, with its type.
    $this->assertSame('fpga', $payload['node_types'][0]['accelerators'][0]['type']);
    $this->assertSame('2× Bittware IA-840F (FPGA, 64 GB memory), 64 GB RAM', $payload['node_types'][0]['summary']);
  }

  /**
   * The node type summary marks Arm CPUs and names non-GPU accelerators.
   */
  public function testNodeTypeSummaryMentionsArmAndAcceleratorTypes(): void {
    $arm = $this->nodeType('Arm node', [
      'field_rp_cpus' => 'Ampere Altra',
      'field_rp_cpu_architecture' => 'arm',
      'field_rp_cpu_count' => 80,
    ], [['vector_engine', NULL, 8, NULL]]);
    $payload = $this->payload($this->resource([$arm], []));

    $this->assertSame('arm', $payload['node_types'][0]['cpu_architecture']);
    $this->assertSame('8× vector engine, 80-core Ampere Altra (Arm)', $payload['node_types'][0]['summary']);
  }

  /**
   * A mixed-GPU node type gives one joined legacy row with summed counts.
   */
  public function testLegacyQueueSpecsFromMixedGpuNode(): void {
    $mixed = $this->nodeType('Mixed', [], [['gpu', 'AMD MI100', 4, 32], ['gpu', 'AMD MI210', 4, 64]], [['q', 1]]);
    $blank = $this->nodeType('Blank counts', [], [['gpu', 'AMD MI100', NULL, NULL], ['gpu', 'AMD MI210', NULL, NULL]], [['q', 2]]);
    $partial = $this->nodeType('Partial', [], [['gpu', 'AMD MI100', 2, NULL], ['gpu', 'AMD MI210', NULL, NULL]], [['q', 3]]);
    $single = $this->nodeType('Single', [], [['gpu', 'NVIDIA H100', 8, 80]], [['q', 4]]);
    $queue = $this->queue('q', 'Purpose', 60);
    $payload = $this->payload($this->resource([$mixed, $blank, $partial, $single], [$queue]));

    [$a, $b, $c, $d] = $payload['queue_specs'];
    $this->assertSame('AMD MI100, AMD MI210', $a['gpu_type']);
    $this->assertSame(8, $a['gpu_count']);
    $this->assertNull($a['gpu_vram']);
    $this->assertSame('AMD MI100, AMD MI210', $b['gpu_type']);
    $this->assertNull($b['gpu_count'], 'Both counts blank gives a null total, not zero.');
    $this->assertNull($b['gpu_vram']);
    $this->assertSame(2, $c['gpu_count'], 'Only the known counts are summed.');
    $this->assertSame('NVIDIA H100', $d['gpu_type']);
    $this->assertSame(8, $d['gpu_count']);
    $this->assertSame(80, $d['gpu_vram']);
  }

  /**
   * Migrating Bridges-2 GPU leaves its legacy rows the same apart from purpose.
   *
   * The rows are the live data for nid 390: ten rows, three queues, six
   * distinct hardware tuples. The migration moves a row's purpose onto the node
   * type when the queue's rows disagree, so purpose is the one field allowed to
   * differ.
   */
  public function testLegacyQueueSpecsMatchPreMigrationRows(): void {
    $rows = [
      ['name' => 'GPU-shared', 'purpose' => 'Jobs using 1 to 4 GPUs of a node, shared with other jobs; charged per GPU.', 'node_count' => 24, 'cpu_type' => '2x Intel Xeon Gold 6248 "Cascade Lake"', 'cpu_count' => 40, 'gpu_type' => 'NVIDIA Tesla V100-32GB SXM2', 'gpu_count' => 8, 'gpu_vram' => 32, 'ram' => 512, 'max_wall_minutes' => 2880],
      ['name' => 'GPU-shared', 'purpose' => 'Jobs using 1 to 4 GPUs of a node, shared with other jobs; charged per GPU.', 'node_count' => 10, 'cpu_type' => '2x Intel Xeon "Sapphire Rapids" 8470', 'cpu_count' => 104, 'gpu_type' => 'NVIDIA H100-80GB SXM5', 'gpu_count' => 8, 'gpu_vram' => 80, 'ram' => 2000, 'max_wall_minutes' => 2880],
      ['name' => 'GPU-shared', 'purpose' => 'Jobs using 1 to 4 GPUs of a node, shared with other jobs; charged per GPU.', 'node_count' => 9, 'cpu_type' => '2x Intel Xeon Gold 6148', 'cpu_count' => 40, 'gpu_type' => 'NVIDIA V100-16GB', 'gpu_count' => 8, 'gpu_vram' => 16, 'ram' => 192, 'max_wall_minutes' => 2880],
      ['name' => 'GPU-shared', 'purpose' => 'Jobs using 1 to 4 GPUs of a node, shared with other jobs; charged per GPU.', 'node_count' => 3, 'cpu_type' => '2x Intel Xeon 6740E', 'cpu_count' => 192, 'gpu_type' => 'NVIDIA L40S-48GB', 'gpu_count' => 8, 'gpu_vram' => 48, 'ram' => 1000, 'max_wall_minutes' => 2880],
      ['name' => 'GPU', 'purpose' => 'Jobs using whole GPU nodes; charged for the full node.', 'node_count' => 23, 'cpu_type' => '2x Intel Xeon Gold 6248 "Cascade Lake"', 'cpu_count' => 40, 'gpu_type' => 'NVIDIA Tesla V100-32GB SXM2', 'gpu_count' => 8, 'gpu_vram' => 32, 'ram' => 512, 'max_wall_minutes' => 2880],
      ['name' => 'GPU', 'purpose' => 'Jobs using whole GPU nodes; charged for the full node.', 'node_count' => 10, 'cpu_type' => '2x Intel Xeon "Sapphire Rapids" 8470', 'cpu_count' => 104, 'gpu_type' => 'NVIDIA H100-80GB SXM5', 'gpu_count' => 8, 'gpu_vram' => 80, 'ram' => 2000, 'max_wall_minutes' => 2880],
      ['name' => 'GPU', 'purpose' => 'Jobs using whole GPU nodes; charged for the full node.', 'node_count' => 9, 'cpu_type' => '2x Intel Xeon Gold 6148', 'cpu_count' => 40, 'gpu_type' => 'NVIDIA V100-16GB', 'gpu_count' => 8, 'gpu_vram' => 16, 'ram' => 192, 'max_wall_minutes' => 2880],
      ['name' => 'GPU', 'purpose' => 'Jobs using whole GPU nodes; charged for the full node.', 'node_count' => 3, 'cpu_type' => '2x Intel Xeon 6740E', 'cpu_count' => 192, 'gpu_type' => 'NVIDIA L40S-48GB', 'gpu_count' => 8, 'gpu_vram' => 48, 'ram' => 1000, 'max_wall_minutes' => 2880],
      ['name' => 'GPU', 'purpose' => 'Jobs using whole GPU nodes; charged for the full node.', 'node_count' => 1, 'cpu_type' => '2x Intel Xeon Platinum 8168', 'cpu_count' => 48, 'gpu_type' => 'NVIDIA Volta V100-32GB', 'gpu_count' => 16, 'gpu_vram' => 32, 'ram' => 1500, 'max_wall_minutes' => 2880],
      ['name' => 'GPU-small', 'purpose' => 'Used for interactive and small or quick GPU jobs on a single V100-32 node.', 'node_count' => 1, 'cpu_type' => '2x Intel Xeon Gold' . "\u{a0}" . '6248', 'cpu_count' => 40, 'gpu_type' => 'NVIDIA Tesla V100-32GB SXM2', 'gpu_count' => 8, 'gpu_vram' => 32, 'ram' => 512, 'max_wall_minutes' => 2880],
    ];
    $paragraphs = [];
    foreach ($rows as $row) {
      $paragraphs[] = $this->legacyQueue($row);
    }
    $node = $this->resource([], $paragraphs);

    $before = $this->payload($node);
    $this->assertSame([], $before['node_types']);
    $this->assertCount(10, $before['queue_specs']);
    $this->assertCount(10, $before['queues'], 'Before migration there is one queue entry per legacy row.');
    $this->assertSame([], $before['queues'][0]['nodes'], 'Queues are present without node types, with no node entries.');

    \Drupal::moduleHandler()->loadInclude('operations_cider', 'php', 'operations_cider.deploy');
    $sandbox = [];
    $passes = 0;
    do {
      operations_cider_deploy_d3090_01_node_types($sandbox);
      $passes++;
    } while (($sandbox['#finished'] ?? 1) < 1 && $passes < 20);
    $this->assertSame([], $sandbox['failed']);

    $after = $this->payload($node);
    $this->assertCount(6, $after['node_types']);
    $this->assertSame(['GPU-shared', 'GPU', 'GPU-small'], array_column($after['queues'], 'name'));

    $strip = static function (array $rows): array {
      return array_map(static function (array $row): array {
        unset($row['purpose']);
        return $row;
      }, $rows);
    };
    $this->assertSame($strip($before['queue_specs']), $strip($after['queue_specs']));
  }

  /**
   * A flagged resource without a curated entry keeps its legacy payload.
   *
   * The placeholder GPU value ("none") makes the migrator refuse the merge, so
   * the deploy hook leaves the resource on its legacy rows and the API payload
   * is exactly what it was before the hook ran.
   */
  public function testFlaggedResourcePayloadIsUnchangedByDeployHook(): void {
    $node = $this->resource([], [
      $this->legacyQueue([
        'name' => 'batch',
        'purpose' => 'Batch jobs',
        'node_count' => 10,
        'cpu_type' => 'AMD EPYC',
        'cpu_count' => 64,
        'gpu_type' => 'None',
        'gpu_count' => NULL,
        'gpu_vram' => NULL,
        'ram' => 256,
        'max_wall_minutes' => 2880,
      ]),
    ]);
    $before = $this->payload($node);

    \Drupal::service('operations_cider.node_type_migration')->setCurated([]);
    \Drupal::moduleHandler()->loadInclude('operations_cider', 'php', 'operations_cider.deploy');
    $sandbox = [];
    operations_cider_deploy_d3090_01_node_types($sandbox);

    $this->assertSame(1, $sandbox['counts']['skipped']);
    $this->assertSame([], $sandbox['failed']);
    $after = $this->payload($node);
    $this->assertSame([], $after['node_types']);
    $this->assertSame($before, $after);
  }

  /**
   * The real curated entries produce the payload the ticket describes.
   *
   * Applies the shipped entries directly (bypassing the legacy hash) to a bare
   * resource and reads the API payload.
   */
  public function testRealCuratedEntriesProducePayload(): void {
    $migration = \Drupal::service('operations_cider.node_type_migration');

    $bridges = $this->applyCurated($migration, 'bridges2-gpu.psc.access-ci.org');
    $queues = array_column($bridges['queues'], NULL, 'name');
    $this->assertStringContainsString('charged for the full node', $queues['GPU']['purpose']);
    $this->assertSame('node', $queues['GPU']['charged_per']);
    $this->assertSame('gpu', $queues['GPU-shared']['charged_per']);
    $nodes = static fn (string $queue): array => array_column($queues[$queue]['nodes'], 'node_count', 'node_type');
    $this->assertSame(24, $nodes('GPU-shared')['V100-32']);
    $this->assertSame(23, $nodes('GPU')['V100-32']);

    $aces = $this->applyCurated($migration, 'aces.tamu.access-ci.org');
    $types = array_column($aces['node_types'], NULL, 'label');
    $specs = array_column($aces['queue_specs'], NULL, 'name');
    $queues = array_column($aces['queues'], NULL, 'name');
    $this->assertSame('fpga', $types['BittWare FPGA node']['accelerators'][0]['type']);
    $this->assertNull($specs['bittware']['gpu_type']);
    $this->assertSame('vector_engine', $types['NEC Vector Engine node']['accelerators'][0]['type']);
    $this->assertNull($specs['nec']['gpu_type']);
    $this->assertNull($specs['nec']['gpu_count']);
    $this->assertSame('arm', $types['Grace Hopper (gh01)']['cpu_architecture']);
    $this->assertSame('interactive', $queues['gh01']['access']);
  }

  /**
   * Applies one real curated entry to a new resource and returns its payload.
   *
   * @return array<string, mixed>
   *   The decoded payload.
   */
  private function applyCurated(object $migration, string $globalId): array {
    $entry = $migration->curated()[$globalId] ?? NULL;
    $this->assertIsArray($entry, "$globalId is in the curated file.");
    $node = $this->resource([], []);
    $migration->apply($node, ['node_types' => $entry['node_types'], 'queues' => $entry['queues']], 'curated');
    return $this->payload(Node::load($node->id()));
  }

  /**
   * A resource with node types but no queues still reports its hardware.
   */
  public function testNodeTypesWithoutQueues(): void {
    $vm = $this->nodeType('Large VM', ['field_rp_cpu_count' => 32, 'field_rp_node_ram' => 128]);
    $node = $this->resource([$vm], []);

    $payload = $this->payload($node);

    $this->assertCount(1, $payload['node_types']);
    $this->assertSame('32-core, 128 GB RAM', $payload['node_types'][0]['summary']);
    $this->assertSame([], $payload['queues']);
    $this->assertSame([], $payload['queue_specs']);

    // The group list must still treat this as having the compute section.
    Node::create([
      'type' => 'resource_group',
      'title' => 'Cloud group',
      'status' => 1,
      'field_cider_resources' => [['target_id' => $node->id()]],
    ])->save();
    $response = ResourceDocumentationController::create($this->container)->listResourceGroups();
    $groups = json_decode($response->getContent(), TRUE)['groups'];
    $this->assertContains('queue_specs', $groups[0]['populated_sections']);
  }

  /**
   * A queue that lists no node types gives one legacy row with null hardware.
   */
  public function testQueueWithoutNodeTypesGivesNullHardwareRow(): void {
    $gpu = $this->nodeType('GPU node', ['field_rp_cpu_count' => 8], [['gpu', 'NVIDIA A100', 4, 80]], [['linked', 3]]);
    $linked = $this->queue('linked', 'Linked', 60);
    $orphan = $this->queue('orphan', 'No hardware', 120);
    $payload = $this->payload($this->resource([$gpu], [$linked, $orphan]));

    $this->assertCount(2, $payload['queue_specs']);
    $row = $payload['queue_specs'][1];
    $this->assertSame('orphan', $row['name']);
    $this->assertSame('No hardware', $row['purpose']);
    foreach (['node_count', 'cpu_count', 'cpu_type', 'gpu_count', 'gpu_type', 'gpu_vram', 'ram', 'summary'] as $key) {
      $this->assertArrayHasKey($key, $row);
      $this->assertNull($row[$key], "$key should be null.");
    }
    $this->assertSame(2, $row['max_wall_time_hours']);
    $this->assertSame([], $payload['queues'][1]['nodes']);
  }

  /**
   * Without node types, queue_specs still comes from the old hardware fields.
   */
  public function testResourceWithoutNodeTypesKeepsLegacyQueueSpecs(): void {
    $legacy = $this->legacyQueue([
      'name' => 'batch',
      'purpose' => 'Batch',
      'node_count' => 5,
      'cpu_type' => 'Intel Xeon',
      'cpu_count' => 48,
      'gpu_type' => NULL,
      'gpu_count' => NULL,
      'gpu_vram' => NULL,
      'ram' => 256,
      'max_wall_minutes' => 90,
    ]);
    $payload = $this->payload($this->resource([], [$legacy]));

    $this->assertSame([], $payload['node_types']);
    $this->assertSame('batch', $payload['queue_specs'][0]['name']);
    $this->assertSame(5, $payload['queue_specs'][0]['node_count']);
    $this->assertSame('Intel Xeon', $payload['queue_specs'][0]['cpu_type']);
    $this->assertSame(1.5, $payload['queue_specs'][0]['max_wall_time_hours']);
    $this->assertSame('5 nodes, 48-core Intel Xeon, 256 GB RAM', $payload['queue_specs'][0]['summary']);
    $this->assertSame('batch', $payload['queues'][0]['name']);
    $this->assertSame([], $payload['queues'][0]['nodes']);
  }

  /**
   * Create a saved node type paragraph with nested accelerator paragraphs.
   *
   * @param string $label
   *   The node type label.
   * @param array<string, mixed> $values
   *   Extra paragraph field values.
   * @param array<int, array{0: string, 1: ?string, 2: ?int, 3: ?int}> $accelerators
   *   Accelerator entries: type, model, count per node, memory per device.
   * @param array<int, array{0: string, 1: ?int}> $queues
   *   Queue name and node count per queue this node type serves.
   */
  private function nodeType(string $label, array $values = [], array $accelerators = [], array $queues = []): Paragraph {
    $acceleratorParagraphs = [];
    foreach ($accelerators as [$type, $model, $count, $memory]) {
      $accelerator = Paragraph::create([
        'type' => 'rp_accelerator',
        'field_rp_accelerator_type' => $type,
        'field_rp_accelerator_model' => $model,
        'field_rp_accelerator_count' => $count,
        'field_rp_accelerator_memory' => $memory,
      ]);
      $accelerator->save();
      $acceleratorParagraphs[] = $accelerator;
    }
    $queueParagraphs = [];
    foreach ($queues as [$name, $count]) {
      $entry = Paragraph::create([
        'type' => 'rp_node_type_queue',
        'field_rp_queue_name' => $name,
        'field_rp_node_count' => $count,
      ]);
      $entry->save();
      $queueParagraphs[] = $entry;
    }
    $paragraph = Paragraph::create([
      'type' => 'rp_node_type',
      'field_rp_node_type_label' => $label,
      'field_rp_accelerators' => $acceleratorParagraphs,
      'field_rp_node_type_queues' => $queueParagraphs,
    ] + $values);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Create a saved queue paragraph.
   *
   * @param string $name
   *   The queue name.
   * @param string|null $purpose
   *   The queue purpose.
   * @param int|null $minutes
   *   Maximum wall time in minutes.
   * @param array<string, mixed> $extra
   *   Extra paragraph field values.
   */
  private function queue(string $name, ?string $purpose, ?int $minutes, array $extra = []): Paragraph {
    $paragraph = Paragraph::create([
      'type' => 'rp_queue_spec',
      'field_rp_queue_name' => $name,
      'field_rp_queue_purpose' => $purpose,
      'field_rp_max_wall_time' => $minutes,
    ] + $extra);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Create a legacy queue paragraph that still holds its own hardware.
   *
   * @param array<string, mixed> $row
   *   A row in the migrator's input shape.
   */
  private function legacyQueue(array $row): Paragraph {
    $paragraph = Paragraph::create([
      'type' => 'rp_queue_spec',
      'field_rp_queue_name' => $row['name'],
      'field_rp_queue_purpose' => $row['purpose'],
      'field_rp_node_count' => $row['node_count'],
      'field_rp_cpus' => $row['cpu_type'],
      'field_rp_cpu_count' => $row['cpu_count'],
      'field_rp_gpus' => $row['gpu_type'],
      'field_rp_gpu_count' => $row['gpu_count'],
      'field_rp_gpu_vram' => $row['gpu_vram'],
      'field_rp_vram' => $row['ram'],
      'field_rp_max_wall_time' => $row['max_wall_minutes'],
    ]);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Create a published resource with the given node type and queue paragraphs.
   */
  private function resource(array $nodeTypes, array $queues): Node {
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Compute Resource',
      'status' => 1,
      'field_rp_node_types' => $nodeTypes,
      'field_rp_queue_specs' => $queues,
    ]);
    $node->save();
    return $node;
  }

  /**
   * The decoded detail payload for a resource.
   */
  private function payload(Node $node): array {
    $response = ResourceDocumentationController::create($this->container)->getResourceByNid($node->id());
    $this->assertSame(200, $response->getStatusCode());
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * Create a published resource whose metrics field holds the given blob.
   */
  private function makeResourceWithMetrics(array $metrics): Node {
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Metrics Resource',
      'status' => 1,
      'field_rp_queue_metrics' => json_encode($metrics),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Call the controller's own decoder rather than reimplementing it here.
   */
  private function decodeMetrics(Node $resource): array {
    $controller = \Drupal::classResolver(
      'Drupal\operations_cider\Controller\ResourceDocumentationController'
    );
    $method = new \ReflectionMethod($controller, 'decodeQueueMetrics');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $resource);
  }

  /**
   * A resource with no metrics decodes to an empty map, not a null or a list.
   *
   * This is the input to the object cast in getResource(). The cast itself is
   * only observable once the response is encoded, so the Cypress test against
   * the live endpoint is what pins it — asserting typeof is "object" and not an
   * array. Kept separate deliberately rather than duplicating the cast here,
   * which would assert that PHP casts, not that the controller does.
   */
  public function testResourceWithoutMetricsDecodesToAnEmptyMap(): void {
    $resource = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'No Metrics Resource',
      'status' => 1,
    ]);
    $resource->save();

    $decoded = $this->decodeMetrics($resource);
    $this->assertSame([], $decoded['queues']);
    $this->assertNull($decoded['updated']);
    $this->assertArrayHasKey('last_data', $decoded);
    $this->assertNull($decoded['last_data']);
  }

  /**
   * Build the payload's documentation_links for a resource, with inheritance.
   */
  private function documentationLinksFor(Node $resource): array {
    $inheritance = \Drupal::service('operations_cider.resource_group_inheritance');
    $clone = clone $resource;
    $inheritance->applyInheritance($clone);

    $controller = \Drupal::classResolver(
      'Drupal\operations_cider\Controller\ResourceDocumentationController'
    );
    $reflection = new \ReflectionClass($controller);

    $map = $reflection->getConstant('DOCUMENTATION_LINK_FIELDS');
    $this->assertIsArray($map, 'DOCUMENTATION_LINK_FIELDS must exist on the controller.');

    $getLink = $reflection->getMethod('getLinkValue');
    $getLink->setAccessible(TRUE);

    $links = [];
    foreach ($map as $key => $field) {
      $links[$key] = $getLink->invoke($controller, $clone, $field);
    }
    return $links;
  }

}
