<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\operations_cider\Traits\XdmodTestTrait;
use Drupal\node\Entity\Node;
use Drupal\operations_cider\Exception\XdmodAuthenticationException;
use Drupal\operations_cider\Exception\XdmodEmptyResultException;
use Drupal\operations_cider\Service\SdsEnrichmentService;
use Drupal\operations_cider\Service\TopSoftwareService;
use GuzzleHttp\Psr7\Response;

/**
 * Covers how TopSoftwareService reacts to XDMoD failures.
 *
 * @group operations_cider
 */
class TopSoftwareServiceTest extends KernelTestBase {

  use XdmodTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'key', 'symfony_mailer', 'operations_cider',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->createResourceFields();
  }

  /**
   * Builds the service under test around a client.
   */
  protected function service($client): TopSoftwareService {
    $sds = $this->createMock(SdsEnrichmentService::class);
    return new TopSoftwareService(
      $client,
      $this->container->get('entity_type.manager'),
      $this->loggerFactory(),
      $this->keyRepository(),
      $sds,
    );
  }

  /**
   * Reads the stored software list of a node, bypassing the entity cache.
   */
  protected function storedSoftware(int $nid): string {
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$nid]);
    return Node::load($nid)->get('field_rp_top_software')->value;
  }

  /**
   * A 401 throws and saves nothing.
   */
  public function testUnauthorizedThrows(): void {
    $node = $this->createResource(1, 'a.example');
    $client = $this->mockClient([new Response(401, [], '{"success":false,"message":"Session Expired"}')]);

    try {
      $this->service($client)->updateAll();
      $this->fail('Expected XdmodAuthenticationException.');
    }
    catch (XdmodAuthenticationException $e) {
      $this->assertSame(401, $e->getStatusCode());
      $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
    }
    $this->assertSame('old-software', $this->storedSoftware($node->id()));
  }

  /**
   * Every XDMoD ID coming back empty throws.
   */
  public function testTotalFailureThrows(): void {
    $this->createResource(1, 'a.example');
    $client = $this->mockClient([new Response(503)]);

    $this->expectException(XdmodEmptyResultException::class);
    $this->service($client)->updateAll();
  }

}
