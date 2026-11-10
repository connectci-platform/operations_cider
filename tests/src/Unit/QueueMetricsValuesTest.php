<?php

namespace Drupal\Tests\operations_cider\Unit;

use Drupal\operations_cider\QueueMetricsValues;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the missing-data rules for XDMoD queue metrics.
 *
 * @group operations_cider
 * @coversDefaultClass \Drupal\operations_cider\QueueMetricsValues
 */
class QueueMetricsValuesTest extends UnitTestCase {

  /**
   * A bare 0 is "no jobs"; a decimal zero is a real zero.
   *
   * @covers ::parseCell
   * @dataProvider providerParseCell
   */
  public function testParseCell(string $cell, ?float $expected): void {
    $this->assertSame($expected, QueueMetricsValues::parseCell($cell));
  }

  /**
   * Cases for testParseCell().
   */
  public static function providerParseCell(): array {
    return [
      'bare zero' => ['0', NULL],
      'padded bare zero' => [' 0 ', NULL],
      'decimal zero' => ['0.00000000', 0.0],
      'empty' => ['', NULL],
      'not available' => ['N/A', NULL],
      'decimal' => ['1.5', 1.5],
      'long decimal' => ['71.92179935', 71.92179935],
    ];
  }

  /**
   * An exact-zero wait is implausible and becomes NULL.
   *
   * @covers ::waitOrNull
   * @dataProvider providerWaitOrNull
   */
  public function testWaitOrNull(?float $value, ?float $expected): void {
    $this->assertSame($expected, QueueMetricsValues::waitOrNull($value));
  }

  /**
   * Cases for testWaitOrNull().
   */
  public static function providerWaitOrNull(): array {
    return [
      'zero' => [0.0, NULL],
      'positive' => [0.5, 0.5],
      'null' => [NULL, NULL],
    ];
  }

  /**
   * The last data date ignores trailing days without values.
   *
   * @covers ::lastDataDate
   * @dataProvider providerLastDataDate
   */
  public function testLastDataDate(array $queues, ?string $expected): void {
    $this->assertSame($expected, QueueMetricsValues::lastDataDate($queues));
  }

  /**
   * Cases for testLastDataDate().
   */
  public static function providerLastDataDate(): array {
    $dates = ['2026-10-17', '2026-10-18', '2026-10-19'];
    return [
      'latest non-null day' => [
        ['a' => ['daily_dates' => $dates, 'daily_wait' => [1.0, 2.0, 3.0], 'daily_wall' => [1.0, 2.0, 3.0]]],
        '2026-10-19',
      ],
      'trailing all-null days ignored' => [
        ['a' => ['daily_dates' => $dates, 'daily_wait' => [1.0, 2.0, NULL], 'daily_wall' => [1.0, NULL, NULL]]],
        '2026-10-18',
      ],
      'wall only counts' => [
        ['a' => ['daily_dates' => $dates, 'daily_wait' => [NULL, NULL, NULL], 'daily_wall' => [NULL, 4.0, NULL]]],
        '2026-10-18',
      ],
      'latest across queues' => [
        [
          'a' => ['daily_dates' => $dates, 'daily_wait' => [1.0, NULL, NULL], 'daily_wall' => [NULL, NULL, NULL]],
          'b' => ['daily_dates' => $dates, 'daily_wait' => [NULL, NULL, NULL], 'daily_wall' => [NULL, NULL, 0.0]],
        ],
        '2026-10-19',
      ],
      'everything null' => [
        ['a' => ['daily_dates' => $dates, 'daily_wait' => [NULL, NULL, NULL], 'daily_wall' => [NULL, NULL, NULL]]],
        NULL,
      ],
      'no queues' => [[], NULL],
      'legacy queue without dates' => [['a' => ['daily_wait' => [1.0]]], NULL],
    ];
  }

}
