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

  /**
   * Prefers last_data, otherwise falls back to the fetch date.
   *
   * @covers ::freshnessDate
   * @dataProvider providerFreshnessDate
   */
  public function testFreshnessDate(array $blob, ?string $date, string $label): void {
    $this->assertSame(
      ['date' => $date, 'label' => $label],
      QueueMetricsFreshness::freshnessDate($blob)
    );
  }

  /**
   * Data provider for testFreshnessDate().
   */
  public static function providerFreshnessDate(): array {
    $through = QueueMetricsFreshness::LABEL_DATA_THROUGH;
    $updated = QueueMetricsFreshness::LABEL_UPDATED;
    return [
      'legacy blob without last_data' => [['updated' => '2026-11-10'], '2026-11-10', $updated],
      'valid last_data' => [['updated' => '2026-11-10', 'last_data' => '2026-10-20'], '2026-10-20', $through],
      'null last_data' => [['updated' => '2026-11-10', 'last_data' => NULL], '2026-11-10', $updated],
      'relative text last_data' => [['updated' => '2026-11-10', 'last_data' => '2 hours ago'], '2026-11-10', $updated],
      'impossible last_data' => [['updated' => '2026-11-10', 'last_data' => '2026-13-40'], '2026-11-10', $updated],
      'non-string last_data' => [['updated' => '2026-11-10', 'last_data' => 123], '2026-11-10', $updated],
      'neither date' => [[], NULL, $updated],
      'empty updated' => [['updated' => '', 'last_data' => NULL], NULL, $updated],
    ];
  }

}
