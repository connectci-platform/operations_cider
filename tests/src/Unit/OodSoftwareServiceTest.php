<?php

namespace Drupal\Tests\operations_cider\Unit;

use Drupal\operations_cider\Service\OodSoftwareService;
use Drupal\operations_cider\Service\SdsEnrichmentService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Tests for OodSoftwareService pure logic (enrichEntries, sortEntries).
 *
 * @group operations_cider
 */
class OodSoftwareServiceTest extends UnitTestCase {

  /**
   * Build a service instance with mocked dependencies.
   */
  protected function makeService(SdsEnrichmentService $sds): OodSoftwareService {
    $etm = $this->prophesize(EntityTypeManagerInterface::class)->reveal();
    $channel = $this->prophesize(LoggerChannelInterface::class)->reveal();
    $lf = $this->prophesize(LoggerChannelFactoryInterface::class);
    $lf->get('operations_cider')->willReturn($channel);
    return new OodSoftwareService($etm, $lf->reveal(), $sds);
  }

  /**
   * Tests that enrichEntries merges SDS fields and preserves the input name.
   */
  public function testEnrichMergesSdsFieldsAndPreservesName(): void {
    $sds = $this->prophesize(SdsEnrichmentService::class);
    $catalog = ['jupyter' => ['software_name' => 'jupyter']];
    $sds->findInSds('Jupyter', $catalog)->willReturn(['software_name' => 'jupyter', 'ai_description' => 'Notebooks']);
    $sds->mapSdsFields(['software_name' => 'jupyter', 'ai_description' => 'Notebooks'])
      ->willReturn(['description' => 'Notebooks', 'research_field' => '', 'web_page' => '', 'documentation' => '']);
    $svc = $this->makeService($sds->reveal());
    $out = $svc->enrichEntries([['name' => 'Jupyter']], $catalog);
    $this->assertSame('Jupyter', $out[0]['name']);
    $this->assertSame('Notebooks', $out[0]['description']);
  }

  /**
   * Tests that enrichEntries leaves unmatched entries unchanged.
   */
  public function testEnrichLeavesUnmatchedEntryUnchanged(): void {
    $sds = $this->prophesize(SdsEnrichmentService::class);
    $sds->findInSds('Unknownapp', [])->willReturn(NULL);
    $svc = $this->makeService($sds->reveal());
    $out = $svc->enrichEntries([['name' => 'Unknownapp']], []);
    $this->assertSame([['name' => 'Unknownapp']], $out);
  }

  /**
   * Tests that sortEntries orders by job_count descending, nulls last.
   */
  public function testSortByJobCountDescNullsLast(): void {
    $sds = $this->prophesize(SdsEnrichmentService::class)->reveal();
    $svc = $this->makeService($sds);
    $in = [['name' => 'A'], ['name' => 'B', 'job_count' => 10], ['name' => 'C', 'job_count' => 50]];
    $out = $svc->sortEntries($in);
    $this->assertSame(['C', 'B', 'A'], array_column($out, 'name'));
  }

  /**
   * Tests that sortEntries preserves input order when all entries lack usage.
   */
  public function testSortPreservesHumanOrderWhenNoUsage(): void {
    $sds = $this->prophesize(SdsEnrichmentService::class)->reveal();
    $svc = $this->makeService($sds);
    $in = [['name' => 'A'], ['name' => 'B'], ['name' => 'C']];
    $out = $svc->sortEntries($in);
    $this->assertSame(['A', 'B', 'C'], array_column($out, 'name'));
  }

}
