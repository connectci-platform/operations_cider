<?php

namespace Drupal\Tests\operations_cider\Unit;

use Drupal\operations_cider\Service\QueueSpecGrouper;
use Drupal\Tests\UnitTestCase;

/**
 * Tests grouping of resource queue-spec rows by queue name.
 *
 * @group operations_cider
 * @coversDefaultClass \Drupal\operations_cider\Service\QueueSpecGrouper
 */
class QueueSpecGrouperTest extends UnitTestCase {

  /**
   * A non-contiguous repeat merges back into the first group.
   *
   * (A, B, A) is three paragraphs but two queues: A's rows stay in editor
   * order and B keeps its place in the list.
   */
  public function testNonContiguousRepeatMerges(): void {
    $rows = [
      ['name' => 'A', 'purpose' => 'p1', 'max_wall' => 10, 'paragraph' => 'a1'],
      ['name' => 'B', 'purpose' => 'p2', 'max_wall' => 20, 'paragraph' => 'b1'],
      ['name' => 'A', 'purpose' => 'p1', 'max_wall' => 10, 'paragraph' => 'a2'],
    ];
    $groups = QueueSpecGrouper::group($rows, []);
    $this->assertSame(['A', 'B'], [$groups[0]['name'], $groups[1]['name']]);
    $this->assertSame(['a1', 'a2'], [$groups[0]['rows'][0]['paragraph'], $groups[0]['rows'][1]['paragraph']]);
    $this->assertSame('b1', $groups[1]['rows'][0]['paragraph']);
  }

  /**
   * Rows with a blank name are skipped.
   */
  public function testBlankNameIsSkipped(): void {
    $rows = [
      ['name' => '', 'purpose' => 'p', 'max_wall' => 10, 'paragraph' => 'x'],
      ['name' => 'A', 'purpose' => 'p', 'max_wall' => 10, 'paragraph' => 'y'],
    ];
    $groups = QueueSpecGrouper::group($rows, []);
    $this->assertCount(1, $groups);
    $this->assertSame('A', $groups[0]['name']);
  }

  /**
   * Numeric-looking names keep first-appearance order.
   *
   * The groups are built keyed by name, and PHP would renumber and reorder
   * keys like "1" and "2", so the result has to come back as a plain
   * 0-indexed list in the order the names were first seen.
   */
  public function testNumericLikeNamesKeepOrder(): void {
    $rows = [
      ['name' => '2', 'purpose' => 'p', 'max_wall' => 10, 'paragraph' => 'x'],
      ['name' => '1', 'purpose' => 'p', 'max_wall' => 10, 'paragraph' => 'y'],
    ];
    $groups = QueueSpecGrouper::group($rows, []);
    $this->assertSame(['2', '1'], [$groups[0]['name'], $groups[1]['name']]);
    $this->assertSame([0, 1], array_keys($groups));
  }

  /**
   * Max wall is hoisted to the group only when every row agrees on it.
   *
   * Uniform gives the value, mixed gives NULL, and a value paired with a
   * blank is mixed rather than shared.
   */
  public function testMaxWallSharedRule(): void {
    $uniform = QueueSpecGrouper::group([
      ['name' => 'A', 'purpose' => 'p', 'max_wall' => 30, 'paragraph' => 'x'],
      ['name' => 'A', 'purpose' => 'p', 'max_wall' => 30, 'paragraph' => 'y'],
    ], []);
    $this->assertSame(30, $uniform[0]['max_wall']);

    $mixed = QueueSpecGrouper::group([
      ['name' => 'A', 'purpose' => 'p', 'max_wall' => 30, 'paragraph' => 'x'],
      ['name' => 'A', 'purpose' => 'p', 'max_wall' => 60, 'paragraph' => 'y'],
    ], []);
    $this->assertNull($mixed[0]['max_wall']);

    $with_blank = QueueSpecGrouper::group([
      ['name' => 'A', 'purpose' => 'p', 'max_wall' => 30, 'paragraph' => 'x'],
      ['name' => 'A', 'purpose' => 'p', 'max_wall' => NULL, 'paragraph' => 'y'],
    ], []);
    $this->assertNull($with_blank[0]['max_wall']);
  }

  /**
   * Purpose is hoisted to the group only when every row agrees on it.
   *
   * Uniform gives the value, mixed gives NULL, and a value paired with a
   * blank is mixed rather than shared.
   */
  public function testPurposeSharedRule(): void {
    $uniform = QueueSpecGrouper::group([
      ['name' => 'A', 'purpose' => 'batch', 'max_wall' => 10, 'paragraph' => 'x'],
      ['name' => 'A', 'purpose' => 'batch', 'max_wall' => 10, 'paragraph' => 'y'],
    ], []);
    $this->assertSame('batch', $uniform[0]['purpose']);

    $mixed = QueueSpecGrouper::group([
      ['name' => 'A', 'purpose' => 'batch', 'max_wall' => 10, 'paragraph' => 'x'],
      ['name' => 'A', 'purpose' => 'debug', 'max_wall' => 10, 'paragraph' => 'y'],
    ], []);
    $this->assertNull($mixed[0]['purpose']);

    $with_blank = QueueSpecGrouper::group([
      ['name' => 'A', 'purpose' => 'batch', 'max_wall' => 10, 'paragraph' => 'x'],
      ['name' => 'A', 'purpose' => '', 'max_wall' => 10, 'paragraph' => 'y'],
    ], []);
    $this->assertNull($with_blank[0]['purpose']);
  }

  /**
   * Metrics are attached by name, and NULL when the queue has none.
   */
  public function testMetricsAttachedByName(): void {
    $metrics = ['A' => ['wait_time' => 5]];
    $groups = QueueSpecGrouper::group([
      ['name' => 'A', 'purpose' => 'p', 'max_wall' => 10, 'paragraph' => 'x'],
      ['name' => 'B', 'purpose' => 'p', 'max_wall' => 10, 'paragraph' => 'y'],
    ], $metrics);
    $this->assertSame(['wait_time' => 5], $groups[0]['metrics']);
    $this->assertNull($groups[1]['metrics']);
  }

  /**
   * Single is TRUE for a one-row queue and FALSE for a two-row queue.
   */
  public function testSingleFlag(): void {
    $groups = QueueSpecGrouper::group([
      ['name' => 'A', 'purpose' => 'p', 'max_wall' => 10, 'paragraph' => 'x'],
      ['name' => 'B', 'purpose' => 'p', 'max_wall' => 10, 'paragraph' => 'y'],
      ['name' => 'B', 'purpose' => 'p', 'max_wall' => 10, 'paragraph' => 'z'],
    ], []);
    $this->assertTrue($groups[0]['single']);
    $this->assertFalse($groups[1]['single']);
  }

}
