<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\operations_cider\Traits\NodeTypeFieldsTrait;

/**
 * Covers the deploy hook that splits queue hardware into node types.
 *
 * @group operations_cider
 */
class NodeTypesDeployHookTest extends KernelTestBase {

  use NodeTypeFieldsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'link', 'key', 'file',
    'entity_reference_revisions', 'paragraphs', 'operations_cider',
  ];

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
    $this->setUpNodeTypeFields();
    \Drupal::moduleHandler()->loadInclude('operations_cider', 'php', 'operations_cider.deploy');
  }

  /**
   * Two queues, one of them on two hardware rows, become node types + queues.
   */
  public function testDeployHookCreatesNodeTypesAndQueues(): void {
    $node = $this->makeResource();
    $this->runDeployHook();

    $node = Node::load($node->id());
    $types = $node->get('field_rp_node_types')->referencedEntities();
    $this->assertSame(['NVIDIA A100', 'AMD MI100, AMD MI210', 'NVIDIA H100'], $this->labelsOf($types));
    // The first type: one GPU entry carrying count and vRAM.
    $gpus = $types[0]->get('field_rp_node_gpus')->referencedEntities();
    $this->assertCount(1, $gpus);
    $this->assertSame('NVIDIA A100', $gpus[0]->get('field_rp_gpus')->value);
    $this->assertSame('4', $gpus[0]->get('field_rp_gpu_count')->value);
    $this->assertSame('80', $gpus[0]->get('field_rp_gpu_vram')->value);
    $this->assertSame('64', $types[0]->get('field_rp_cpu_count')->value);
    $this->assertSame('256', $types[0]->get('field_rp_vram')->value);

    // The mixed-GPU row splits into two GPU entries with blank count and vRAM.
    $mixed = $types[1]->get('field_rp_node_gpus')->referencedEntities();
    $this->assertCount(2, $mixed);
    $this->assertSame('AMD MI100', $mixed[0]->get('field_rp_gpus')->value);
    $this->assertTrue($mixed[0]->get('field_rp_gpu_count')->isEmpty());
    $this->assertSame('AMD MI210', $mixed[1]->get('field_rp_gpus')->value);

    $queues = $node->get('field_rp_queue_specs')->referencedEntities();
    $this->assertCount(2, $queues);
    $this->assertSame('gpu', $queues[0]->get('field_rp_queue_name')->value);
    $this->assertSame('GPU jobs', $queues[0]->get('field_rp_queue_purpose')->value);
    $this->assertSame('2880', $queues[0]->get('field_rp_max_wall_time')->value);
    $this->assertSame(
      [['NVIDIA A100', '100'], ['NVIDIA H100', '25']],
      $this->queueNodes($queues[0])
    );
    $this->assertSame('mixed', $queues[1]->get('field_rp_queue_name')->value);
    $this->assertSame('60', $queues[1]->get('field_rp_max_wall_time')->value);
    $this->assertSame([['AMD MI100, AMD MI210', '8']], $this->queueNodes($queues[1]));

    $this->assertSame('Split queue hardware into node types (D8-3090)', $node->getRevisionLogMessage());
  }

  /**
   * Running the update again changes nothing.
   */
  public function testDeployHookIsIdempotent(): void {
    $node = $this->makeResource();
    $this->runDeployHook();
    $paragraphs = \Drupal::entityQuery('paragraph')->accessCheck(FALSE)->count()->execute();
    $first = Node::load($node->id());
    $labels = $this->labelsOf($first->get('field_rp_node_types')->referencedEntities());
    $revision = $first->getRevisionId();

    $this->runDeployHook();

    $second = Node::load($node->id());
    $this->assertSame($paragraphs, (int) \Drupal::entityQuery('paragraph')->accessCheck(FALSE)->count()->execute());
    $this->assertSame($labels, $this->labelsOf($second->get('field_rp_node_types')->referencedEntities()));
    $this->assertCount(2, $second->get('field_rp_queue_specs'));
    $this->assertSame($revision, $second->getRevisionId());
  }

  /**
   * A resource that already has node types is left alone.
   */
  public function testResourceWithNodeTypesIsSkipped(): void {
    $existing = Paragraph::create([
      'type' => 'rp_node_type',
      'field_rp_node_type_label' => 'Hand entered',
    ]);
    $existing->save();
    $queue = Paragraph::create([
      'type' => 'rp_queue_spec',
      'field_rp_queue_name' => 'q',
      'field_rp_gpus' => 'NVIDIA A100',
    ]);
    $queue->save();
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Already migrated',
      'field_rp_node_types' => [$existing],
      'field_rp_queue_specs' => [$queue],
    ]);
    $node->save();

    $this->runDeployHook();

    $node = Node::load($node->id());
    $this->assertSame(['Hand entered'], $this->labelsOf($node->get('field_rp_node_types')->referencedEntities()));
    $this->assertCount(0, $node->get('field_rp_queue_specs')->first()->entity->get('field_rp_queue_nodes'));
  }

  /**
   * A resource whose queue rows no longer load is skipped, not retried forever.
   */
  public function testResourceWithUnloadableQueuesIsSkipped(): void {
    $queue = Paragraph::create([
      'type' => 'rp_queue_spec',
      'field_rp_queue_name' => 'gone',
      'field_rp_gpus' => 'NVIDIA A100',
    ]);
    $queue->save();
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Dangling queue',
      'field_rp_queue_specs' => [$queue],
    ]);
    $node->save();
    // Deleting the paragraph leaves the reference behind on the node.
    $queue->delete();
    $migrated = $this->makeResource();

    $sandbox = [];
    $passes = 0;
    do {
      operations_cider_deploy_d3090_01_node_types($sandbox);
      $passes++;
    } while (($sandbox['#finished'] ?? 1) < 1 && $passes < 20);

    $this->assertEquals(1, $sandbox['#finished'], 'The update finishes.');
    $this->assertSame([], $sandbox['failed']);
    $this->assertEquals([$node->id()], $sandbox['skipped']);
    $this->assertTrue(Node::load($node->id())->get('field_rp_node_types')->isEmpty());
    $this->assertCount(3, Node::load($migrated->id())->get('field_rp_node_types'));
  }

  /**
   * Creates a resource with three legacy rows: queue gpu twice, queue mixed.
   */
  private function makeResource(): Node {
    $rows = [
      [
        'field_rp_queue_name' => 'gpu',
        'field_rp_queue_purpose' => 'GPU jobs',
        'field_rp_max_wall_time' => 2880,
        'field_rp_node_count' => 100,
        'field_rp_cpus' => 'AMD EPYC',
        'field_rp_cpu_count' => 64,
        'field_rp_vram' => 256,
        'field_rp_gpus' => 'NVIDIA A100',
        'field_rp_gpu_count' => 4,
        'field_rp_gpu_vram' => 80,
      ],
      [
        'field_rp_queue_name' => 'mixed',
        'field_rp_queue_purpose' => 'Mixed AMD',
        'field_rp_max_wall_time' => 60,
        'field_rp_node_count' => 8,
        'field_rp_cpus' => 'AMD EPYC',
        'field_rp_cpu_count' => 64,
        'field_rp_vram' => 512,
        'field_rp_gpus' => 'AMD MI100, AMD MI210',
        'field_rp_gpu_count' => 8,
      ],
      [
        'field_rp_queue_name' => 'gpu',
        'field_rp_queue_purpose' => 'GPU jobs',
        'field_rp_max_wall_time' => 2880,
        'field_rp_node_count' => 25,
        'field_rp_cpus' => 'Intel Xeon',
        'field_rp_cpu_count' => 112,
        'field_rp_vram' => 2048,
        'field_rp_gpus' => 'NVIDIA H100',
        'field_rp_gpu_count' => 4,
        'field_rp_gpu_vram' => 80,
      ],
    ];
    $refs = [];
    foreach ($rows as $row) {
      $paragraph = Paragraph::create(['type' => 'rp_queue_spec'] + $row);
      $paragraph->save();
      $refs[] = $paragraph;
    }
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => 'Legacy resource',
      'field_rp_queue_specs' => $refs,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Runs the deploy hook to completion, as drush deploy batches would.
   */
  private function runDeployHook(): void {
    $sandbox = [];
    $passes = 0;
    do {
      operations_cider_deploy_d3090_01_node_types($sandbox);
      $passes++;
    } while (($sandbox['#finished'] ?? 1) < 1 && $passes < 20);
    $this->assertSame([], $sandbox['failed'], 'No resource should fail to migrate.');
  }

  /**
   * Labels of node type paragraphs, in order.
   *
   * @param \Drupal\paragraphs\ParagraphInterface[] $types
   *   Node type paragraphs.
   *
   * @return list<string>
   *   The labels.
   */
  private function labelsOf(array $types): array {
    return array_map(static fn ($t) => $t->get('field_rp_node_type_label')->value, $types);
  }

  /**
   * Node type label and node count pairs of a queue, in order.
   *
   * @return list<array{0: string, 1: string}>
   *   Pairs of label and count.
   */
  private function queueNodes(Paragraph $queue): array {
    $pairs = [];
    foreach ($queue->get('field_rp_queue_nodes')->referencedEntities() as $entry) {
      $pairs[] = [$entry->get('field_rp_queue_node_type')->value, $entry->get('field_rp_node_count')->value];
    }
    return $pairs;
  }

}
