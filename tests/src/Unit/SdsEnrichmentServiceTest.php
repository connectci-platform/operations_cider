<?php

namespace Drupal\Tests\operations_cider\Unit;

use Drupal\operations_cider\Service\SdsEnrichmentService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * @group operations_cider
 * @coversDefaultClass \Drupal\operations_cider\Service\SdsEnrichmentService
 */
class SdsEnrichmentServiceTest extends UnitTestCase {

  protected function makeService(): SdsEnrichmentService {
    $http = $this->prophesize(ClientInterface::class)->reveal();
    $etm = $this->prophesize(EntityTypeManagerInterface::class)->reveal();
    $channel = $this->prophesize(LoggerChannelInterface::class)->reveal();
    $lf = $this->prophesize(LoggerChannelFactoryInterface::class);
    $lf->get('operations_cider')->willReturn($channel);
    $keys = $this->prophesize(KeyRepositoryInterface::class)->reveal();
    return new SdsEnrichmentService($http, $etm, $lf->reveal(), $keys);
  }

  public function testFindInSdsExactMatch(): void {
    $svc = $this->makeService();
    $catalog = ['gromacs' => ['software_name' => 'gromacs', 'ai_description' => 'MD']];
    $this->assertSame('MD', $svc->findInSds('GROMACS', $catalog)['ai_description']);
  }

  public function testFindInSdsAlias(): void {
    // NAME_ALIASES maps 'r' => ['r-base', 'r-project'].
    $svc = $this->makeService();
    $catalog = ['r-base' => ['software_name' => 'r-base', 'ai_description' => 'R lang']];
    $this->assertSame('R lang', $svc->findInSds('r', $catalog)['ai_description']);
  }

  public function testFindInSdsNormalizedMatch(): void {
    // 'q-espresso' normalizes to 'qespresso'.
    $svc = $this->makeService();
    $catalog = ['q_espresso' => ['software_name' => 'q_espresso', 'ai_description' => 'QE']];
    $this->assertSame('QE', $svc->findInSds('q-espresso', $catalog)['ai_description']);
  }

  public function testFindInSdsNoMatchReturnsNull(): void {
    $svc = $this->makeService();
    $this->assertNull($svc->findInSds('nonexistent', ['gromacs' => []]));
  }

  public function testMapSdsFieldsPrefersAiDescription(): void {
    $svc = $this->makeService();
    $mapped = $svc->mapSdsFields([
      'ai_description' => 'AI', 'software_description' => 'plain',
      'ai_research_field' => 'Chem', 'software_web_page' => 'http://x',
      'software_documentation' => 'http://docs',
    ]);
    $this->assertSame('AI', $mapped['description']);
    $this->assertSame('Chem', $mapped['research_field']);
    $this->assertSame('http://x', $mapped['web_page']);
    $this->assertSame('http://docs', $mapped['documentation']);
  }

  public function testMapSdsFieldsFallsBackToSoftwareDescription(): void {
    $svc = $this->makeService();
    $mapped = $svc->mapSdsFields(['software_description' => 'plain']);
    $this->assertSame('plain', $mapped['description']);
    $this->assertSame('', $mapped['research_field']);
  }

  public function testGoldenEnrichmentMatchesOriginalContract(): void {
    // Reproduces the original TopSoftwareService::enrichWithSds merge for a
    // representative catalog: ai_description wins over software_description,
    // alias resolves, normalized match resolves, unmatched left as-is.
    $svc = $this->makeService();
    $catalog = [
      'gromacs' => ['software_name' => 'gromacs', 'ai_description' => 'AI GROMACS', 'software_description' => 'plain', 'ai_research_field' => 'Bio', 'software_web_page' => 'http://g', 'software_documentation' => 'http://gdoc'],
      'r-base'  => ['software_name' => 'r-base', 'software_description' => 'R only', 'software_web_page' => 'http://r'],
      'q_espresso' => ['software_name' => 'q_espresso', 'ai_description' => 'QE'],
    ];
    $entries = [
      ['name' => 'gromacs', 'job_count' => 5],
      ['name' => 'r'],            // alias -> r-base
      ['name' => 'q-espresso'],   // normalized -> q_espresso
      ['name' => 'unmatched'],
    ];
    $out = [];
    foreach ($entries as $e) {
      $hit = $svc->findInSds($e['name'], $catalog);
      $out[] = $hit ? array_merge($e, $svc->mapSdsFields($hit)) : $e;
    }
    $this->assertSame('AI GROMACS', $out[0]['description']);
    $this->assertSame('Bio', $out[0]['research_field']);
    $this->assertSame(5, $out[0]['job_count']); // preserved
    $this->assertSame('R only', $out[1]['description']); // software_description fallback
    $this->assertSame('QE', $out[2]['description']); // normalized alias
    $this->assertSame(['name' => 'unmatched'], $out[3]); // untouched
  }

}
