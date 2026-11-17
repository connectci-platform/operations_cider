<?php

namespace Drupal\Tests\operations_cider\Unit;

use Drupal\operations_cider\QueueSpecMigrator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests conversion of legacy queue rows into node types and queues.
 *
 * Fixtures are real rows from the production resources on 2026-11-13.
 *
 * @group operations_cider
 * @coversDefaultClass \Drupal\operations_cider\QueueSpecMigrator
 */
class QueueSpecMigratorTest extends UnitTestCase {

  /**
   * Bridges-2 GPU has six node types and three queues.
   *
   * @covers ::migrate
   */
  public function testBridges2GpuCollapsesToNodeTypesAndThreeQueues(): void {
    $result = (new QueueSpecMigrator())->migrate($this->bridges2Gpu());
    $this->assertAllLists($result);

    $this->assertSame([
      'NVIDIA Tesla V100-32GB SXM2',
      'NVIDIA H100-80GB SXM5',
      'NVIDIA V100-16GB',
      'NVIDIA L40S-48GB',
      'NVIDIA Volta V100-32GB',
      'NVIDIA Tesla V100-32GB SXM2 2',
    ], array_column($result['node_types'], 'label'));
    $this->assertSame(['GPU-shared', 'GPU', 'GPU-small'], array_column($result['queues'], 'name'));

    $v100 = $result['node_types'][0];
    $this->assertSame('2x Intel Xeon Gold 6248 "Cascade Lake"', $v100['cpu_type']);
    $this->assertSame(40, $v100['cpu_count']);
    $this->assertSame(512, $v100['ram']);
    $this->assertNull($v100['node_count']);
    $this->assertSame([['type' => 'NVIDIA Tesla V100-32GB SXM2', 'count' => 8, 'vram' => 32]], $v100['gpus']);
    $this->assertSame(16, $result['node_types'][4]['gpus'][0]['count']);

    [$shared, $gpu, $small] = $result['queues'];
    $this->assertSame(
      [
        ['node_type' => 'NVIDIA Tesla V100-32GB SXM2', 'node_count' => 24],
        ['node_type' => 'NVIDIA H100-80GB SXM5', 'node_count' => 10],
        ['node_type' => 'NVIDIA V100-16GB', 'node_count' => 9],
        ['node_type' => 'NVIDIA L40S-48GB', 'node_count' => 3],
      ],
      $shared['nodes']
    );
    $this->assertCount(5, $gpu['nodes']);
    $this->assertSame(23, $gpu['nodes'][0]['node_count']);
    $this->assertSame([['node_type' => 'NVIDIA Tesla V100-32GB SXM2 2', 'node_count' => 1]], $small['nodes']);
    $this->assertSame(2880, $gpu['max_wall_minutes']);

    // Purposes differ per row, so queues have none and node types keep the
    // first row's text as a description.
    $this->assertNull($shared['purpose']);
    $this->assertNull($gpu['purpose']);
    $this->assertStringStartsWith('V100-32 Node. Jobs using 1 to 4', $v100['description']);
    $this->assertStringStartsWith('H100-80 Node.', $result['node_types'][1]['description']);
    $this->assertStringStartsWith('DGX-2 special node.', $result['node_types'][4]['description']);
    // GPU-small has one row, so its purpose stays on the queue.
    $this->assertSame('Used for interactive and small or quick GPU jobs on a single V100-32 node.', $small['purpose']);
    $this->assertNull($result['node_types'][5]['description']);
    $this->assertReportContains($result, 'different purposes');
  }

  /**
   * ACES gpu_debug shares one purpose across two node types.
   *
   * @covers ::migrate
   */
  public function testAcesGpuDebugMergesSharedPurpose(): void {
    $result = (new QueueSpecMigrator())->migrate($this->aces());
    $this->assertAllLists($result);

    $queues = array_column($result['queues'], NULL, 'name');
    $debug = $queues['gpu_debug'];
    $this->assertSame('Short GPU test and debug runs.', $debug['purpose']);
    $this->assertSame(120, $debug['max_wall_minutes']);
    $this->assertSame(
      [
        ['node_type' => 'NVIDIA A30', 'node_count' => 3],
        ['node_type' => 'NVIDIA H100', 'node_count' => 3],
      ],
      $debug['nodes']
    );
    // The shared purpose is not copied onto node types.
    $types = array_column($result['node_types'], NULL, 'label');
    $this->assertNull($types['NVIDIA A30']['description']);
    $this->assertNull($queues['gh01']['max_wall_minutes']);
    // gh01 has the same GPU model as gpu but different hardware.
    $this->assertSame('NVIDIA H100 (480 GB RAM)', $queues['gh01']['nodes'][0]['node_type']);
  }

  /**
   * Mixed GPU text splits into one entry per model and is reported.
   *
   * @covers ::migrate
   */
  public function testMixedGpuTextSplits(): void {
    $result = (new QueueSpecMigrator())->migrate($this->deltaGpu());
    $this->assertAllLists($result);

    $types = array_column($result['node_types'], NULL, 'label');
    $this->assertSame(
      [
        ['type' => 'AMD MI100', 'count' => NULL, 'vram' => NULL],
        ['type' => 'AMD MI210', 'count' => NULL, 'vram' => NULL],
      ],
      $types['AMD MI100, AMD MI210']['gpus']
    );
    $this->assertSame(2048, $types['AMD MI100, AMD MI210']['ram']);
    // gpuMI100x8 and its interactive twin share one node type.
    $queues = array_column($result['queues'], NULL, 'name');
    $this->assertSame('AMD MI100, AMD MI210', $queues['gpuMI100x8']['nodes'][0]['node_type']);
    $this->assertSame('AMD MI100, AMD MI210', $queues['gpuMI100x8-interactive']['nodes'][0]['node_type']);
    $this->assertCount(12, $result['queues']);
    $this->assertReportContains($result, 'AMD MI100, AMD MI210');
    $this->assertReportContains($result, 'count 8, vRAM 32');
    $this->assertSame([['type' => 'NVIDIA A40', 'count' => 4, 'vram' => 48]], $types['NVIDIA A40']['gpus']);
  }

  /**
   * A plus sign also separates GPU models.
   *
   * @covers ::migrate
   */
  public function testPlusSeparatesGpuModels(): void {
    $result = (new QueueSpecMigrator())->migrate([
      ['name' => 'q', 'gpu_type' => 'NVIDIA A100 + NVIDIA L40S', 'gpu_count' => 2, 'gpu_vram' => NULL, 'cpu_type' => 'EPYC'],
    ]);
    $this->assertSame(['NVIDIA A100', 'NVIDIA L40S'], array_column($result['node_types'][0]['gpus'], 'type'));
  }

  /**
   * GPU type "None" means no GPU, so equal CPU-only rows merge.
   *
   * On ACES the four no-GPU queues share one node type, and the report asks a
   * person to split it, since bittware and memverge are different hardware.
   *
   * @covers ::migrate
   */
  public function testPlaceholderGpuMeansNoGpu(): void {
    $result = (new QueueSpecMigrator())->migrate($this->aces());
    $this->assertAllLists($result);

    $label = 'Intel Xeon 8468 (Sapphire Rapids)';
    $types = array_column($result['node_types'], NULL, 'label');
    $queues = array_column($result['queues'], NULL, 'name');
    $this->assertArrayHasKey($label, $types);
    $this->assertSame([], $types[$label]['gpus']);
    $this->assertSame(96, $types[$label]['cpu_count']);
    $this->assertSame(512, $types[$label]['ram']);
    $expected = ['bittware' => 2, 'nextsilicon' => 2, 'memverge' => 10, 'cpu' => 88];
    foreach ($expected as $name => $count) {
      $this->assertSame([['node_type' => $label, 'node_count' => $count]], $queues[$name]['nodes'], $name);
    }
    $this->assertCount(6, $result['node_types']);
    $this->assertReportContains($result, 'Queues "bittware", "nextsilicon", "memverge", "cpu" share node type "' . $label . '"');
    $this->assertSame('CPU-only jobs that need no accelerator; the default choice for general compute.', $queues['cpu']['purpose']);
    $this->assertSame(4320, $queues['cpu']['max_wall_minutes']);
  }

  /**
   * A CPU-only resource gets no "share node type" report line.
   *
   * @covers ::migrate
   */
  public function testCpuOnlyResourceMergesWithoutReport(): void {
    $row = ['cpu_type' => '2x AMD EPYC 7763 "Milan"', 'cpu_count' => 128, 'ram' => 256, 'gpu_type' => 'None', 'gpu_count' => 0, 'gpu_vram' => 0];
    $result = (new QueueSpecMigrator())->migrate([
      ['name' => 'cpu', 'node_count' => 132] + $row,
      ['name' => 'cpu-preempt', 'node_count' => 132] + $row,
    ]);
    $this->assertCount(1, $result['node_types']);
    $this->assertSame('2x AMD EPYC 7763 "Milan"', $result['node_types'][0]['label']);
    $this->assertSame([], $result['report']);
  }

  /**
   * Entirely blank rows also stay separate and use the queue name.
   *
   * @covers ::migrate
   */
  public function testBlankHardwareRowsDoNotMerge(): void {
    $result = (new QueueSpecMigrator())->migrate([
      ['name' => 'alpha', 'node_count' => 3],
      ['name' => 'beta', 'node_count' => 4],
    ]);
    $this->assertAllLists($result);
    $this->assertSame(['alpha', 'beta'], array_column($result['node_types'], 'label'));
    $this->assertSame([], $result['node_types'][0]['gpus']);
    $this->assertReportContains($result, 'Queue "alpha" has no hardware entered');
  }

  /**
   * Expanse CPU queues point to one node type with their own counts.
   *
   * @covers ::migrate
   */
  public function testExpanseCpuKeepsPerQueueCounts(): void {
    $result = (new QueueSpecMigrator())->migrate($this->expanseCpu());
    $this->assertAllLists($result);

    $counts = [];
    foreach ($result['queues'] as $queue) {
      $this->assertCount(1, $queue['nodes']);
      $counts[$queue['name']] = [$queue['nodes'][0]['node_type'], $queue['nodes'][0]['node_count']];
    }
    $this->assertSame(
      [
        'compute' => ['AMD EPYC 7742', 734],
        'debug' => ['AMD EPYC 7742', 2],
        'preempt' => ['AMD EPYC 7742', 726],
        'large-shared' => ['AMD EPYC 7742 (2000 GB RAM)', 4],
        'shared' => ['AMD EPYC 7742', 734],
      ],
      $counts
    );
    $this->assertSame([], $result['node_types'][0]['gpus']);
    $this->assertSame(30, $result['queues'][1]['max_wall_minutes']);
    $this->assertSame(10080, $result['queues'][2]['max_wall_minutes']);
    $this->assertSame('Compute Node Usage', $result['queues'][0]['purpose']);
    // Purposes are trimmed.
    $this->assertStringStartsWith('Priority access', $result['queues'][1]['purpose']);
  }

  /**
   * The later colliding CPU type gets its RAM in the label.
   *
   * @covers ::migrate
   */
  public function testCpuLabelCollisionAddsRam(): void {
    $result = (new QueueSpecMigrator())->migrate($this->expanseCpu());
    $this->assertSame(['AMD EPYC 7742', 'AMD EPYC 7742 (2000 GB RAM)'], array_column($result['node_types'], 'label'));
    $this->assertSame([256, 2000], array_column($result['node_types'], 'ram'));
    $this->assertSame([128, 128], array_column($result['node_types'], 'cpu_count'));
  }

  /**
   * A suffixed label is reported so the RP can rename it.
   *
   * @covers ::migrate
   */
  public function testLabelSuffixIsReported(): void {
    $expanse = (new QueueSpecMigrator())->migrate($this->expanseCpu());
    $this->assertReportContains($expanse, '"AMD EPYC 7742 (2000 GB RAM)"');

    // Same RAM cannot be told apart by RAM, so a number is appended.
    $bridges = (new QueueSpecMigrator())->migrate($this->bridges2Gpu());
    $this->assertReportContains($bridges, '"NVIDIA Tesla V100-32GB SXM2 2"');
  }

  /**
   * Deprecated RAM text with no number is reported.
   *
   * @covers ::migrate
   */
  public function testLegacyRamOnlyIsReported(): void {
    $result = (new QueueSpecMigrator())->migrate($this->neocortex());
    $this->assertAllLists($result);
    $this->assertNull($result['node_types'][0]['ram']);
    $this->assertReportContains($result, '24 TiB total across the HPE Superdome Flex system');
    $this->assertReportContains($result, 'no Node RAM');

    // A legacy text next to a numeric RAM is not reported (Expanse CPU).
    $expanse = (new QueueSpecMigrator())->migrate($this->expanseCpu());
    foreach ($expanse['report'] as $line) {
      $this->assertStringNotContainsString('DDR4', $line);
    }
  }

  /**
   * Differing max wall times use the first non-empty value and are reported.
   *
   * @covers ::migrate
   */
  public function testDifferingMaxWallIsReported(): void {
    $result = (new QueueSpecMigrator())->migrate([
      ['name' => 'q', 'cpu_type' => 'A', 'max_wall_minutes' => NULL],
      ['name' => 'q', 'cpu_type' => 'B', 'max_wall_minutes' => 60],
      ['name' => 'q', 'cpu_type' => 'C', 'max_wall_minutes' => 30],
    ]);
    $this->assertSame(60, $result['queues'][0]['max_wall_minutes']);
    $this->assertReportContains($result, 'different max wall times');
  }

  /**
   * Numeric-looking queue names keep their order and stay strings.
   *
   * @covers ::migrate
   */
  public function testNumericNamesKeepOrder(): void {
    $result = (new QueueSpecMigrator())->migrate([
      ['name' => '2', 'cpu_type' => '2', 'node_count' => 5],
      ['name' => '1', 'cpu_type' => '1', 'node_count' => 6],
    ]);
    $this->assertAllLists($result);
    $this->assertSame(['2', '1'], array_column($result['queues'], 'name'));
    $this->assertSame(['2', '1'], array_column($result['node_types'], 'label'));
    $this->assertSame('2', $result['queues'][0]['nodes'][0]['node_type']);
  }

  /**
   * Empty input gives empty lists.
   *
   * @covers ::migrate
   */
  public function testEmptyInput(): void {
    $this->assertSame(['node_types' => [], 'queues' => [], 'report' => []], (new QueueSpecMigrator())->migrate([]));
  }

  /**
   * Asserts every array in the result is a list.
   *
   * @param array<string, mixed> $result
   *   The migrator result.
   */
  private function assertAllLists(array $result): void {
    $this->assertTrue(array_is_list($result['node_types']));
    $this->assertTrue(array_is_list($result['queues']));
    $this->assertTrue(array_is_list($result['report']));
    foreach ($result['node_types'] as $type) {
      $this->assertTrue(array_is_list($type['gpus']));
    }
    foreach ($result['queues'] as $queue) {
      $this->assertTrue(array_is_list($queue['nodes']));
    }
  }

  /**
   * Asserts some report line contains the text.
   *
   * @param array<string, mixed> $result
   *   The migrator result.
   * @param string $needle
   *   Text to find.
   */
  private function assertReportContains(array $result, string $needle): void {
    foreach ($result['report'] as $line) {
      if (str_contains($line, $needle)) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $this->fail("No report line contains \"$needle\": " . implode(' | ', $result['report']));
  }

  /**
   * Live rows of Bridges-2 GPU.
   *
   * @return array<int, array<string, mixed>>
   *   Legacy rows.
   */
  private function bridges2Gpu(): array {
    return [
      [
        'name' => 'GPU-shared',
        'purpose' => 'V100-32 Node. Jobs using 1 to 4 of a node\'s V100-32 GPUs, shared with other jobs; charged per GPU.',
        'node_count' => 24,
        'cpu_type' => '2x Intel Xeon Gold 6248 "Cascade Lake"',
        'cpu_count' => 40,
        'gpu_type' => 'NVIDIA Tesla V100-32GB SXM2',
        'gpu_count' => 8,
        'gpu_vram' => 32,
        'ram' => 512,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'GPU-shared',
        'purpose' => 'H100-80 Node. Jobs using 1 to 4 of a node\'s H100-80 GPUs, shared with other jobs; charged per GPU.',
        'node_count' => 10,
        'cpu_type' => '2x Intel Xeon "Sapphire Rapids" 8470',
        'cpu_count' => 104,
        'gpu_type' => 'NVIDIA H100-80GB SXM5',
        'gpu_count' => 8,
        'gpu_vram' => 80,
        'ram' => 2000,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'GPU-shared',
        'purpose' => 'V100-16 Node. Jobs using 1 to 4 of a node\'s V100-16 GPUs, shared with other jobs; charged per GPU.',
        'node_count' => 9,
        'cpu_type' => '2x Intel Xeon Gold 6148',
        'cpu_count' => 40,
        'gpu_type' => 'NVIDIA V100-16GB',
        'gpu_count' => 8,
        'gpu_vram' => 16,
        'ram' => 192,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'GPU-shared',
        'purpose' => 'L40S-48 Node. Jobs using 1 to 4 of a node\'s L40S-48 GPUs, shared with other jobs; charged per GPU.',
        'node_count' => 3,
        'cpu_type' => '2x Intel Xeon 6740E',
        'cpu_count' => 192,
        'gpu_type' => 'NVIDIA L40S-48GB',
        'gpu_count' => 8,
        'gpu_vram' => 48,
        'ram' => 1000,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'GPU',
        'purpose' => 'V100-32 Node. Jobs using one or more whole V100-32 nodes, all eight GPUs each; charged for the full node.',
        'node_count' => 23,
        'cpu_type' => '2x Intel Xeon Gold 6248 "Cascade Lake"',
        'cpu_count' => 40,
        'gpu_type' => 'NVIDIA Tesla V100-32GB SXM2',
        'gpu_count' => 8,
        'gpu_vram' => 32,
        'ram' => 512,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'GPU',
        'purpose' => 'H100-80 Node. Jobs using one or more whole H100-80 nodes, all eight GPUs each; charged for the full node.',
        'node_count' => 10,
        'cpu_type' => '2x Intel Xeon "Sapphire Rapids" 8470',
        'cpu_count' => 104,
        'gpu_type' => 'NVIDIA H100-80GB SXM5',
        'gpu_count' => 8,
        'gpu_vram' => 80,
        'ram' => 2000,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'GPU',
        'purpose' => 'V100-16 Node. Jobs using one or more whole V100-16 nodes, all eight GPUs each; charged for the full node.',
        'node_count' => 9,
        'cpu_type' => '2x Intel Xeon Gold 6148',
        'cpu_count' => 40,
        'gpu_type' => 'NVIDIA V100-16GB',
        'gpu_count' => 8,
        'gpu_vram' => 16,
        'ram' => 192,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'GPU',
        'purpose' => 'L40S-48 Node. Jobs using one or more whole L40S-48 nodes, all eight GPUs each; charged for the full node.',
        'node_count' => 3,
        'cpu_type' => '2x Intel Xeon 6740E',
        'cpu_count' => 192,
        'gpu_type' => 'NVIDIA L40S-48GB',
        'gpu_count' => 8,
        'gpu_vram' => 48,
        'ram' => 1000,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'GPU',
        'purpose' => 'DGX-2 special node. Jobs using the whole DGX-2 node and all 16 V100-32 GPUs; charged for the full node.',
        'node_count' => 1,
        'cpu_type' => '2x Intel Xeon Platinum 8168',
        'cpu_count' => 48,
        'gpu_type' => 'NVIDIA Volta V100-32GB',
        'gpu_count' => 16,
        'gpu_vram' => 32,
        'ram' => 1500,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'GPU-small',
        'purpose' => 'Used for interactive and small or quick GPU jobs on a single V100-32 node.',
        'node_count' => 1,
        'cpu_type' => '2x Intel Xeon Gold 6248',
        'cpu_count' => 40,
        'gpu_type' => 'NVIDIA Tesla V100-32GB SXM2',
        'gpu_count' => 8,
        'gpu_vram' => 32,
        'ram' => 512,
        'max_wall_minutes' => 2880,
      ],
    ];
  }

  /**
   * Live rows of ACES.
   *
   * @return array<int, array<string, mixed>>
   *   Legacy rows.
   */
  private function aces(): array {
    return [
      [
        'name' => 'nec',
        'purpose' => 'Memory-bandwidth-bound, vectorizable code such as sparse linear algebra, where throughput limits performance more than compute.',
        'node_count' => 1,
        'cpu_type' => 'Intel Xeon 8268 (Cascade Lake)',
        'cpu_count' => 48,
        'gpu_type' => 'NEC Vector Engine Type 20B-P',
        'gpu_count' => 8,
        'gpu_vram' => 48,
        'ram' => 768,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'bittware',
        'purpose' => 'Custom hardware acceleration on FPGAs, for algorithms that gain from a tailored datapath instead of a fixed CPU or GPU.',
        'node_count' => 2,
        'cpu_type' => 'Intel Xeon 8468 (Sapphire Rapids)',
        'cpu_count' => 96,
        'gpu_type' => 'None',
        'gpu_count' => 0,
        'gpu_vram' => 0,
        'ram' => 512,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'nextsilicon',
        'purpose' => 'Speeding up existing HPC code on a coprocessor that profiles the run and reconfigures to it, with no code rewrite (by request).',
        'node_count' => 2,
        'cpu_type' => 'Intel Xeon 8468 (Sapphire Rapids)',
        'cpu_count' => 96,
        'gpu_type' => 'None',
        'gpu_count' => 0,
        'gpu_vram' => 0,
        'ram' => 512,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'gpu',
        'purpose' => 'GPU-accelerated work such as deep-learning training and CUDA applications.',
        'node_count' => 8,
        'cpu_type' => 'Intel Xeon 8468 (Sapphire Rapids)',
        'cpu_count' => 96,
        'gpu_type' => 'NVIDIA H100',
        'gpu_count' => 2,
        'gpu_vram' => 80,
        'ram' => 512,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'memverge',
        'purpose' => 'Large-memory jobs that use MemVerge Memory Machine to expand RAM with Intel Optane, for working sets that exceed a node\'s normal memory.',
        'node_count' => 10,
        'cpu_type' => 'Intel Xeon 8468 (Sapphire Rapids)',
        'cpu_count' => 96,
        'gpu_type' => 'None',
        'gpu_count' => 0,
        'gpu_vram' => 0,
        'ram' => 512,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'pvc',
        'purpose' => 'AI/ML and HPC written with Intel\'s oneAPI/SYCL, or CUDA code ported to Intel GPUs.',
        'node_count' => 30,
        'cpu_type' => 'Intel Xeon 8468 (Sapphire Rapids)',
        'cpu_count' => 96,
        'gpu_type' => 'Intel Data Center GPU Max 1100 (Ponte Vecchio)',
        'gpu_count' => 8,
        'gpu_vram' => 48,
        'ram' => 512,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'cpu',
        'purpose' => 'CPU-only jobs that need no accelerator; the default choice for general compute.',
        'node_count' => 88,
        'cpu_type' => 'Intel Xeon 8468 (Sapphire Rapids)',
        'cpu_count' => 96,
        'gpu_type' => 'None',
        'gpu_count' => 0,
        'gpu_vram' => 0,
        'ram' => 512,
        'max_wall_minutes' => 4320,
      ],
      [
        'name' => 'gpu_debug',
        'purpose' => 'Short GPU test and debug runs.',
        'node_count' => 3,
        'cpu_type' => 'Intel Xeon 8468 (Sapphire Rapids)',
        'cpu_count' => 96,
        'gpu_type' => 'NVIDIA A30',
        'gpu_count' => 2,
        'gpu_vram' => 24,
        'ram' => 512,
        'max_wall_minutes' => 120,
      ],
      [
        'name' => 'gpu_debug',
        'purpose' => 'Short GPU test and debug runs.',
        'node_count' => 3,
        'cpu_type' => 'Intel Xeon 8468 (Sapphire Rapids)',
        'cpu_count' => 96,
        'gpu_type' => 'NVIDIA H100',
        'gpu_count' => 2,
        'gpu_vram' => 80,
        'ram' => 512,
        'max_wall_minutes' => 120,
      ],
      [
        'name' => 'gh01',
        'purpose' => 'Not a batch partition. GPU work that outgrows normal GPU memory, using Grace-Hopper\'s unified CPU-GPU memory and fast NVLink; reached over SSH, not Slurm.',
        'node_count' => 1,
        'cpu_type' => 'ARM Neoverse V2 (NVIDIA Grace ARM)',
        'cpu_count' => 72,
        'gpu_type' => 'NVIDIA H100',
        'gpu_count' => 1,
        'gpu_vram' => 96,
        'ram' => 480,
        'max_wall_minutes' => NULL,
      ],
    ];
  }

  /**
   * Live rows of Expanse CPU.
   *
   * @return array<int, array<string, mixed>>
   *   Legacy rows.
   */
  private function expanseCpu(): array {
    return [
      [
        'name' => 'compute',
        'purpose' => 'Compute Node Usage',
        'node_count' => 734,
        'cpu_type' => 'AMD EPYC 7742',
        'cpu_count' => 128,
        'gpu_type' => NULL,
        'gpu_count' => NULL,
        'gpu_vram' => NULL,
        'ram' => 256,
        'legacy_ram' => '256 GB DDR4 DRAM',
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'debug',
        'purpose' => '	Priority access to shared nodes set aside for testing of jobs with short wall time and limited resources',
        'node_count' => 2,
        'cpu_type' => 'AMD EPYC 7742',
        'cpu_count' => 128,
        'gpu_type' => NULL,
        'gpu_count' => NULL,
        'gpu_vram' => NULL,
        'ram' => 256,
        'legacy_ram' => '256 GB DDR4 DRAM',
        'max_wall_minutes' => 30,
      ],
      [
        'name' => 'preempt',
        'purpose' => 'Non-refundable discounted jobs to run on free nodes that can be pre-empted by jobs submitted to any other queue',
        'node_count' => 726,
        'cpu_type' => 'AMD EPYC 7742',
        'cpu_count' => 128,
        'gpu_type' => NULL,
        'gpu_count' => NULL,
        'gpu_vram' => NULL,
        'ram' => 256,
        'legacy_ram' => '256 GB DDR4 DRAM',
        'max_wall_minutes' => 10080,
      ],
      [
        'name' => 'large-shared',
        'purpose' => 'Single-node jobs using large memory up to 2 TB (minimum memory required 256G)',
        'node_count' => 4,
        'cpu_type' => 'AMD EPYC 7742',
        'cpu_count' => 128,
        'gpu_type' => NULL,
        'gpu_count' => NULL,
        'gpu_vram' => NULL,
        'ram' => 2000,
        'legacy_ram' => '256 GB DDR4 DRAM',
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'shared',
        'purpose' => 'Jobs using part of a single 256 GB node, sharing the node with other jobs.',
        'node_count' => 734,
        'cpu_type' => 'AMD EPYC 7742',
        'cpu_count' => 128,
        'gpu_type' => NULL,
        'gpu_count' => NULL,
        'gpu_vram' => NULL,
        'ram' => 256,
        'legacy_ram' => '256 GB DDR4 DRAM',
        'max_wall_minutes' => 2880,
      ],
    ];
  }

  /**
   * Live rows of Delta GPU.
   *
   * @return array<int, array<string, mixed>>
   *   Legacy rows.
   */
  private function deltaGpu(): array {
    return [
      [
        'name' => 'gpuA100x4',
        'purpose' => 'Standard production GPU jobs; this is Delta’s default GPU partition',
        'node_count' => 100,
        'cpu_type' => '1x AMD EPYC 7763 "Milan"',
        'cpu_count' => 64,
        'gpu_type' => 'NVIDIA A100',
        'gpu_count' => 4,
        'gpu_vram' => 40,
        'ram' => 256,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'gpuA100x4-preempt',
        'purpose' => 'Checkpointing-capable A100 jobs that can tolerate interruption and loss of unsaved progress',
        'node_count' => 100,
        'cpu_type' => '1x AMD EPYC 7763 "Milan"',
        'cpu_count' => 64,
        'gpu_type' => 'NVIDIA A100',
        'gpu_count' => 4,
        'gpu_vram' => 40,
        'ram' => 256,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'gpuA40x4',
        'purpose' => 'Production jobs that benefit from A40 GPU memory or ray-tracing support',
        'node_count' => 100,
        'cpu_type' => '1x AMD EPYC 7763 "Milan"',
        'cpu_count' => 64,
        'gpu_type' => 'NVIDIA A40',
        'gpu_count' => 4,
        'gpu_vram' => 48,
        'ram' => 256,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'gpuA40x4-preempt',
        'purpose' => 'Checkpointing-capable A40 jobs that can tolerate interruption and loss of unsaved progress',
        'node_count' => 100,
        'cpu_type' => '1x AMD EPYC 7763 "Milan"',
        'cpu_count' => 64,
        'gpu_type' => 'NVIDIA A40',
        'gpu_count' => 4,
        'gpu_vram' => 48,
        'ram' => 256,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'gpuH200x8',
        'purpose' => 'Memory-intensive or FP8/FP16 production jobs; maximum 1 node per job',
        'node_count' => 8,
        'cpu_type' => '2x Intel Xeon Platinum 8558',
        'cpu_count' => 96,
        'gpu_type' => 'NVIDIA H200',
        'gpu_count' => 8,
        'gpu_vram' => 141,
        'ram' => 2048,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'gpuA100x8',
        'purpose' => 'Large-scale production jobs that need up to eight A100 GPUs and 2 TB of node memory',
        'node_count' => 6,
        'cpu_type' => '2x AMD EPYC 7763 "Milan"',
        'cpu_count' => 128,
        'gpu_type' => 'NVIDIA A100',
        'gpu_count' => 8,
        'gpu_vram' => 40,
        'ram' => 2048,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'gpuMI100x8',
        'purpose' => 'AMD GPU and ROCm workloads, or large-memory jobs that can use this single-node partition',
        'node_count' => 1,
        'cpu_type' => '2x AMD EPYC 7763 "Milan"',
        'cpu_count' => 128,
        'gpu_type' => 'AMD MI100, AMD MI210',
        'gpu_count' => 8,
        'gpu_vram' => 32,
        'ram' => 2048,
        'max_wall_minutes' => 2880,
      ],
      [
        'name' => 'gpuA100x4-interactive',
        'purpose' => 'Short interactive sessions for development, testing, and debugging; maximum 4 nodes per job',
        'node_count' => 100,
        'cpu_type' => '1x AMD EPYC 7763 "Milan"',
        'cpu_count' => 64,
        'gpu_type' => 'NVIDIA A100',
        'gpu_count' => 4,
        'gpu_vram' => 40,
        'ram' => 256,
        'max_wall_minutes' => 60,
      ],
      [
        'name' => 'gpuA40x4-interactive',
        'purpose' => 'Short interactive sessions for development, testing, and debugging; maximum 4 nodes per job',
        'node_count' => 100,
        'cpu_type' => '1x AMD EPYC 7763 "Milan"',
        'cpu_count' => 64,
        'gpu_type' => 'NVIDIA A40',
        'gpu_count' => 4,
        'gpu_vram' => 48,
        'ram' => 256,
        'max_wall_minutes' => 60,
      ],
      [
        'name' => 'gpuH200x8-interactive',
        'purpose' => 'Short interactive H200 sessions for development, testing, and tuning; maximum 1 node per job',
        'node_count' => 8,
        'cpu_type' => '2x Intel Xeon Platinum 8558',
        'cpu_count' => 96,
        'gpu_type' => 'NVIDIA H200',
        'gpu_count' => 8,
        'gpu_vram' => 141,
        'ram' => 2048,
        'max_wall_minutes' => 60,
      ],
      [
        'name' => 'gpuA100x8-interactive',
        'purpose' => 'Short interactive multi-GPU sessions for development and testing; maximum 2 nodes per job',
        'node_count' => 6,
        'cpu_type' => '2x AMD EPYC 7763 "Milan"',
        'cpu_count' => 128,
        'gpu_type' => 'NVIDIA A100',
        'gpu_count' => 8,
        'gpu_vram' => 40,
        'ram' => 2048,
        'max_wall_minutes' => 60,
      ],
      [
        'name' => 'gpuMI100x8-interactive',
        'purpose' => 'Short interactive AMD GPU sessions for development and testing; maximum 1 node per job',
        'node_count' => 1,
        'cpu_type' => '2x AMD EPYC 7763 "Milan"',
        'cpu_count' => 128,
        'gpu_type' => 'AMD MI100, AMD MI210',
        'gpu_count' => 8,
        'gpu_vram' => 32,
        'ram' => 2048,
        'max_wall_minutes' => 60,
      ],
    ];
  }

  /**
   * Live rows of Neocortex SDFlex.
   *
   * @return array<int, array<string, mixed>>
   *   Legacy rows.
   */
  private function neocortex(): array {
    return [
      [
        'name' => 'sdf',
        'purpose' => 'Large-memory CPU workloads, preprocessing, compilation, data staging, and Cerebras CS-2 host workflows',
        'node_count' => 2,
        'cpu_type' => 'Intel Xeon Platinum 8280L',
        'cpu_count' => NULL,
        'gpu_type' => NULL,
        'gpu_count' => NULL,
        'gpu_vram' => NULL,
        'ram' => NULL,
        'legacy_ram' => '24 TiB total across the HPE Superdome Flex system',
        'max_wall_minutes' => 2880,
      ],
    ];
  }

}
