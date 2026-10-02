<?php

namespace Drupal\Tests\operations_cider\Unit;

use Drupal\operations_cider\QueueMetricsFreshness;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the staleness cutoff for cached XDMoD queue metrics.
 *
 * @group operations_cider
 * @coversDefaultClass \Drupal\operations_cider\QueueMetricsFreshness
 */
class QueueMetricsFreshnessTest extends UnitTestCase {

  /**
   * Noon UTC on 2026-10-02, the reference "now" for each case.
   */
  private const NOW = 1790942400;

  /**
   * Covers the threshold edges and the inputs with no usable date.
   *
   * @covers ::isStale
   * @dataProvider providerIsStale
   */
  public function testIsStale(?string $updated, bool $expected): void {
    $this->assertSame($expected, QueueMetricsFreshness::isStale($updated, self::NOW));
  }

  /**
   * Data provider for testIsStale().
   */
  public static function providerIsStale(): array {
    return [
      'no metrics' => [NULL, FALSE],
      'empty string' => ['', FALSE],
      'refreshed today' => ['2026-10-02', FALSE],
      '44 days old' => ['2026-08-19', FALSE],
      // Midnight 45 days back is 45.5 days before noon, past the cutoff.
      '45 days old' => ['2026-08-18', TRUE],
      '46 days old' => ['2026-08-17', TRUE],
      'the 2026-06-16 outage' => ['2026-06-16', TRUE],
      // The amp_dev fixture value; Cypress expects its sparklines to render.
      'relative fixture text' => ['2 hours ago', FALSE],
      'impossible date' => ['2026-02-30', FALSE],
    ];
  }

}
