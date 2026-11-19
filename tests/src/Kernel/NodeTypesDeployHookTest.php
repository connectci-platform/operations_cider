<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\operations_cider\NodeTypeMigration;
use Drupal\operations_cider\QueueSpecMigrator;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\operations_cider\Traits\NodeTypeFieldsTrait;

/**
 * Covers the deploy hook that splits queue hardware into node types.
 *
 * @group operations_cider
 */
class NodeTypesDeployHookTest extends KernelTestBase {

  use NodeTypeFieldsTrait;

  private const BUNDLE = 'access_active_resources_from_cid';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'link', 'key', 'file', 'options',
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
    FieldStorageConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_access_global_resource_id',
      'entity_type' => 'node',
      'bundle' => self::BUNDLE,
    ])->save();
    \Drupal::moduleHandler()->loadInclude('operations_cider', 'php', 'operations_cider.deploy');
    // Tests must not depend on the real curated file.
    $this->migration()->setCurated([]);
  }

  /**
   * A clean resource migrates automatically.
   */
  public function testCleanResourceMigratesAutomatically(): void {
    $node = $this->makeResource('Clean', 'clean.example.org');
    $originalQueues = array_map(static fn ($q) => $q->id(), $node->get('field_rp_queue_specs')->referencedEntities());

    $result = $this->runDeployHook();
    $this->assertNull($result['exception']);
    $this->assertStringContainsString('0 curated, 1 automatic, 0 skipped, 0 failed', $result['summary']);

    $node = $this->reload($node);
    $types = $node->get('field_rp_node_types')->referencedEntities();
    $this->assertSame(['NVIDIA A100', 'NVIDIA H100', 'AMD EPYC'], $this->labelsOf($types));

    $accelerators = $types[0]->get('field_rp_accelerators')->referencedEntities();
    $this->assertCount(1, $accelerators);
    $this->assertSame('gpu', $accelerators[0]->get('field_rp_accelerator_type')->value);
    $this->assertSame('NVIDIA A100', $accelerators[0]->get('field_rp_accelerator_model')->value);
    $this->assertSame('4', $accelerators[0]->get('field_rp_accelerator_count')->value);
    $this->assertSame('80', $accelerators[0]->get('field_rp_accelerator_memory')->value);
    $this->assertSame('64', $types[0]->get('field_rp_cpu_count')->value);
    $this->assertSame('256', $types[0]->get('field_rp_node_ram')->value);
    $this->assertCount(0, $types[2]->get('field_rp_accelerators'));

    // Each node type lists the queues it serves, with the per-queue count.
    $this->assertSame([['gpu', '100']], $this->queueEntries($types[0]));
    $this->assertSame([['gpu', '25']], $this->queueEntries($types[1]));
    $this->assertSame([['cpu', '8']], $this->queueEntries($types[2]));

    // One queue paragraph per name; the first legacy paragraph is reused.
    $queues = $node->get('field_rp_queue_specs')->referencedEntities();
    $this->assertSame(['gpu', 'cpu'], array_map(static fn ($q) => $q->get('field_rp_queue_name')->value, $queues));
    $this->assertEquals($originalQueues[0], $queues[0]->id());
    $this->assertEquals($originalQueues[2], $queues[1]->id());
    $this->assertSame('GPU jobs', $queues[0]->get('field_rp_queue_purpose')->value);
    $this->assertSame('2880', $queues[0]->get('field_rp_max_wall_time')->value);
    $this->assertTrue($queues[0]->get('field_rp_charged_per')->isEmpty());
    $this->assertTrue($queues[0]->get('field_rp_queue_access')->isEmpty());
    $this->assertSame('60', $queues[1]->get('field_rp_max_wall_time')->value);
  }

  /**
   * A curated entry is applied when the legacy rows still match its hash.
   */
  public function testCuratedEntryIsAppliedWhenHashMatches(): void {
    // Flagged by the placeholder GPU value, so only the entry can migrate it.
    $node = $this->makeResource('Curated', 'curated.example.org', 'none');
    $entry = $this->curatedEntry($node);
    $this->migration()->setCurated(['curated.example.org' => $entry]);

    $result = $this->runDeployHook();
    $this->assertNull($result['exception']);
    $this->assertStringContainsString('1 curated, 0 automatic, 0 skipped', $result['summary']);

    $node = $this->reload($node);
    $types = $node->get('field_rp_node_types')->referencedEntities();
    $this->assertSame(['Hand written'], $this->labelsOf($types));
    $this->assertSame('arm', $types[0]->get('field_rp_cpu_architecture')->value);
    $accelerators = $types[0]->get('field_rp_accelerators')->referencedEntities();
    $this->assertSame('fpga', $accelerators[0]->get('field_rp_accelerator_type')->value);
    $this->assertSame([['gpu', '12']], $this->queueEntries($types[0]));

    $queues = $node->get('field_rp_queue_specs')->referencedEntities();
    $this->assertCount(1, $queues);
    $this->assertSame('gpu', $queues[0]->get('field_rp_queue_name')->value);
    $this->assertSame('node', $queues[0]->get('field_rp_charged_per')->value);
    $this->assertSame('batch', $queues[0]->get('field_rp_queue_access')->value);
  }

  /**
   * A curated entry is skipped and reported when the legacy rows changed.
   */
  public function testCuratedEntryIsSkippedWhenLegacyRowsChanged(): void {
    $node = $this->makeResource('Edited', 'edited.example.org', 'none');
    $entry = $this->curatedEntry($node);
    $this->migration()->setCurated(['edited.example.org' => $entry]);

    // An editor changes a row after the entry was written.
    $queue = $node->get('field_rp_queue_specs')->first()->entity;
    $queue->set('field_rp_cpu_count', 128);
    $queue->save();
    $revision = $this->reload($node)->getRevisionId();

    $result = $this->runDeployHook();
    $this->assertNull($result['exception']);
    $this->assertStringContainsString('0 curated, 0 automatic, 1 skipped', $result['summary']);

    $node = $this->reload($node);
    $this->assertTrue($node->get('field_rp_node_types')->isEmpty());
    $this->assertSame($revision, $node->getRevisionId());
    $this->assertSame('128', $node->get('field_rp_queue_specs')->first()->entity->get('field_rp_cpu_count')->value);

    $plan = $this->migration()->plan($node);
    $this->assertSame('skipped', $plan['action']);
    $this->assertStringContainsString('legacy rows changed', implode(' ', $plan['reasons']));
  }

  /**
   * A flagged resource without a curated entry keeps its legacy rows.
   */
  public function testFlaggedResourceWithoutEntryKeepsLegacyRows(): void {
    $node = $this->makeResource('Flagged', 'flagged.example.org', 'none');
    $before = $this->migration()->legacyRows($this->reload($node));
    $revision = $this->reload($node)->getRevisionId();
    $paragraphs = $this->paragraphCount();

    $result = $this->runDeployHook();
    $this->assertNull($result['exception']);
    $this->assertStringContainsString('0 curated, 0 automatic, 1 skipped', $result['summary']);

    $node = $this->reload($node);
    $this->assertTrue($node->get('field_rp_node_types')->isEmpty());
    $this->assertSame($revision, $node->getRevisionId());
    $this->assertSame($paragraphs, $this->paragraphCount());
    $this->assertSame($before, $this->migration()->legacyRows($node));
  }

  /**
   * A second run changes nothing.
   */
  public function testDeployHookIsIdempotent(): void {
    $node = $this->makeResource('Clean', 'clean.example.org');
    $this->runDeployHook();
    $first = $this->reload($node);
    $labels = $this->labelsOf($first->get('field_rp_node_types')->referencedEntities());
    $revision = $first->getRevisionId();
    $paragraphs = $this->paragraphCount();

    $result = $this->runDeployHook();
    $this->assertStringContainsString('0 curated, 0 automatic, 0 skipped, 0 failed of 0 resources', $result['summary']);

    $second = $this->reload($node);
    $this->assertSame($paragraphs, $this->paragraphCount());
    $this->assertSame($labels, $this->labelsOf($second->get('field_rp_node_types')->referencedEntities()));
    $this->assertCount(2, $second->get('field_rp_queue_specs'));
    $this->assertSame($revision, $second->getRevisionId());
  }

  /**
   * One failing resource does not stop the others; the hook throws at the end.
   */
  public function testFailureIsReportedAfterTheOthersFinish(): void {
    $bad = $this->makeResource('Broken curated', 'broken.example.org');
    $entry = $this->curatedEntry($bad);
    $entry['queues'][0]['charged_per'] = 'bogus';
    $this->migration()->setCurated(['broken.example.org' => $entry]);
    $good = $this->makeResource('Fine', 'fine.example.org');

    $result = $this->runDeployHook();

    $this->assertInstanceOf(\RuntimeException::class, $result['exception']);
    $this->assertStringContainsString('Broken curated', $result['exception']->getMessage());
    $this->assertStringContainsString('1 failed', $result['exception']->getMessage());
    $this->assertStringNotContainsString('Fine (nid', $result['exception']->getMessage());
    $this->assertCount(3, $this->reload($good)->get('field_rp_node_types'));
    $this->assertTrue($this->reload($bad)->get('field_rp_node_types')->isEmpty());
  }

  /**
   * A resource that already has node types is not a candidate.
   */
  public function testResourceWithNodeTypesIsNoCandidate(): void {
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
      'type' => self::BUNDLE,
      'title' => 'Already migrated',
      'field_rp_node_types' => [$existing],
      'field_rp_queue_specs' => [$queue],
    ]);
    $node->save();
    $this->assertNotContains((int) $node->id(), $this->migration()->candidateIds());

    $result = $this->runDeployHook();

    $this->assertStringContainsString('of 0 resources', $result['summary']);
    $node = $this->reload($node);
    $this->assertSame(['Hand entered'], $this->labelsOf($node->get('field_rp_node_types')->referencedEntities()));
    $this->assertCount(0, $node->get('field_rp_node_types')->first()->entity->get('field_rp_node_type_queues'));
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
      'type' => self::BUNDLE,
      'title' => 'Dangling queue',
      'field_rp_queue_specs' => [$queue],
    ]);
    $node->save();
    // Deleting the paragraph leaves the reference behind on the node.
    $queue->delete();
    $migrated = $this->makeResource('Clean', 'clean.example.org');

    $result = $this->runDeployHook();

    $this->assertNull($result['exception']);
    $this->assertEquals(1, $result['sandbox']['#finished'], 'The update finishes.');
    $this->assertStringContainsString('0 curated, 1 automatic, 1 skipped, 0 failed of 2 resources', $result['summary']);
    $this->assertTrue($this->reload($node)->get('field_rp_node_types')->isEmpty());
    $this->assertCount(3, $this->reload($migrated)->get('field_rp_node_types'));
  }

  /**
   * Creates a resource with three legacy rows: queue gpu twice, queue cpu.
   *
   * @param string $title
   *   The resource title.
   * @param string $globalId
   *   The global resource id.
   * @param string|null $placeholder
   *   When set, the GPU type of the first row (for example "none" to make the
   *   resource flagged).
   */
  private function makeResource(string $title, string $globalId, ?string $placeholder = NULL): Node {
    $rows = [
      [
        'field_rp_queue_name' => 'gpu',
        'field_rp_queue_purpose' => 'GPU jobs',
        'field_rp_max_wall_time' => 2880,
        'field_rp_node_count' => 100,
        'field_rp_cpus' => 'AMD EPYC',
        'field_rp_cpu_count' => 64,
        'field_rp_vram' => 256,
        'field_rp_gpus' => $placeholder ?? 'NVIDIA A100',
        'field_rp_gpu_count' => 4,
        'field_rp_gpu_vram' => 80,
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
      [
        'field_rp_queue_name' => 'cpu',
        'field_rp_queue_purpose' => 'CPU jobs',
        'field_rp_max_wall_time' => 60,
        'field_rp_node_count' => 8,
        'field_rp_cpus' => 'AMD EPYC',
        'field_rp_cpu_count' => 64,
        'field_rp_vram' => 512,
      ],
    ];
    $refs = [];
    foreach ($rows as $row) {
      $paragraph = Paragraph::create(['type' => 'rp_queue_spec'] + $row);
      $paragraph->save();
      $refs[] = $paragraph;
    }
    $node = Node::create([
      'type' => self::BUNDLE,
      'title' => $title,
      'field_access_global_resource_id' => $globalId,
      'field_rp_queue_specs' => $refs,
    ]);
    $node->save();
    return $node;
  }

  /**
   * A valid curated entry for a resource, with the hash of its current rows.
   *
   * @return array<string, mixed>
   *   The entry.
   */
  private function curatedEntry(Node $node): array {
    $rows = $this->migration()->legacyRows($this->reload($node));
    return [
      'title' => $node->label(),
      'legacy_hash' => QueueSpecMigrator::legacyHash($rows),
      'notes' => 'Test entry.',
      'node_types' => [[
        'label' => 'Hand written',
        'description' => NULL,
        'node_count' => 12,
        'cpu_type' => 'Ampere Altra',
        'cpu_architecture' => 'arm',
        'cpu_count' => 80,
        'ram' => 512,
        'accelerators' => [['type' => 'fpga', 'model' => 'Xilinx U250', 'count' => 2, 'memory' => 64]],
        'queues' => [['name' => 'gpu', 'node_count' => 12]],
      ],
      ],
      'queues' => [[
        'name' => 'gpu',
        'purpose' => 'Hand written purpose',
        'max_wall_minutes' => 1440,
        'charged_per' => 'node',
        'access' => 'batch',
      ],
      ],
    ];
  }

  /**
   * The migration service from the container.
   */
  private function migration(): NodeTypeMigration {
    return \Drupal::service('operations_cider.node_type_migration');
  }

  /**
   * Runs the deploy hook to completion, as drush deploy batches would.
   *
   * @return array{summary: ?string, exception: ?\Throwable, sandbox: array<string, mixed>}
   *   The last summary, the exception the hook threw (if any) and the sandbox.
   */
  private function runDeployHook(): array {
    $sandbox = [];
    $summary = NULL;
    $exception = NULL;
    $passes = 0;
    try {
      do {
        $summary = operations_cider_deploy_d3090_01_node_types($sandbox);
        $passes++;
      } while (($sandbox['#finished'] ?? 1) < 1 && $passes < 20);
    }
    catch (\RuntimeException $e) {
      $exception = $e;
      $summary = $e->getMessage();
    }
    return ['summary' => $summary, 'exception' => $exception, 'sandbox' => $sandbox];
  }

  /**
   * Reloads a node from storage.
   */
  private function reload(Node $node): Node {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  /**
   * Number of paragraphs in storage.
   */
  private function paragraphCount(): int {
    return (int) \Drupal::entityQuery('paragraph')->accessCheck(FALSE)->count()->execute();
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
   * Queue name and node count pairs a node type lists, in order.
   *
   * @return list<array{0: string, 1: string}>
   *   Pairs of queue name and count.
   */
  private function queueEntries(Paragraph $type): array {
    $pairs = [];
    foreach ($type->get('field_rp_node_type_queues')->referencedEntities() as $entry) {
      $pairs[] = [$entry->get('field_rp_queue_name')->value, $entry->get('field_rp_node_count')->value];
    }
    return $pairs;
  }

}
