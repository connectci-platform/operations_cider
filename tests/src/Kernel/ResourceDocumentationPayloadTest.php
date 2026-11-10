<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

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

  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'link', 'key', 'operations_cider',
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

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'Resource'])->save();
    NodeType::create(['type' => 'resource_group', 'name' => 'Resource Group'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'node',
      'bundle' => 'resource_group',
      'settings' => ['handler' => 'default'],
    ])->save();

    $linkFields = array_merge(array_keys(self::INHERITED_FIELDS), [self::OWN_FIELD]);
    foreach ($linkFields as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'link',
      ])->save();
      foreach (['access_active_resources_from_cid', 'resource_group'] as $bundle) {
        FieldConfig::create([
          'field_name' => $name,
          'entity_type' => 'node',
          'bundle' => $bundle,
        ])->save();
      }
    }

    // getResource() looks a resource up by this field, so the endpoint test
    // needs it even though the link and metrics logic does not.
    FieldStorageConfig::create([
      'field_name' => 'field_cider_resource_id',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cider_resource_id',
      'entity_type' => 'node',
      'bundle' => 'access_active_resources_from_cid',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_rp_queue_metrics',
      'entity_type' => 'node',
      'type' => 'string_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_rp_queue_metrics',
      'entity_type' => 'node',
      'bundle' => 'access_active_resources_from_cid',
    ])->save();
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
   * Decoding keeps a null wait_time as null rather than dropping it or using 0.
   *
   * The encoded response is pinned by the Cypress resource-api test.
   */
  public function testNullWaitTimeEncodesAsJsonNull(): void {
    $resource = $this->makeResourceWithMetrics([
      'updated' => '2026-11-10',
      'last_data' => NULL,
      'queues' => ['wholenode' => ['wait_time' => NULL, 'job_count' => 5]],
    ]);
    $decoded = $this->decodeMetrics($resource);
    $json = json_encode((object) $decoded['queues']);
    $this->assertSame('{"wholenode":{"wait_time":null,"job_count":5}}', $json);
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
