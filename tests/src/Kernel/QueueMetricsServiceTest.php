<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\operations_cider\Traits\XdmodTestTrait;
use Drupal\node\Entity\Node;
use Drupal\operations_cider\Exception\XdmodAuthenticationException;
use Drupal\operations_cider\Exception\XdmodEmptyResultException;
use Drupal\operations_cider\Service\QueueMetricsService;
use GuzzleHttp\Psr7\Response;

/**
 * Covers how QueueMetricsService reacts to XDMoD failures.
 *
 * A rejected token must fail the job loudly; a flaky resource must not.
 *
 * @group operations_cider
 */
class QueueMetricsServiceTest extends KernelTestBase {

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
  protected function service($client): QueueMetricsService {
    return new QueueMetricsService(
      $client,
      $this->container->get('entity_type.manager'),
      $this->loggerFactory(),
      $this->keyRepository(),
    );
  }

  /**
   * Reads the stored metrics of a node, bypassing the entity cache.
   */
  protected function storedMetrics(int $nid): string {
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$nid]);
    return Node::load($nid)->get('field_rp_queue_metrics')->value;
  }

  /**
   * A 401 throws and saves nothing.
   */
  public function testUnauthorizedThrows(): void {
    $node = $this->createResource(1, 'a.example');
    $client = $this->mockClient(array_fill(0, 5, new Response(401, [], '{"success":false,"message":"Session Expired"}')));

    try {
      $this->service($client)->updateAll();
      $this->fail('Expected XdmodAuthenticationException.');
    }
    catch (XdmodAuthenticationException $e) {
      $this->assertSame(401, $e->getStatusCode());
      $this->assertSame('xdmod_api', $e->getKeyName());
      // (e) The token is never part of the message.
      $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
      $this->assertStringContainsString('Session Expired', $e->getMessage());
    }
    $this->assertSame('old-metrics', $this->storedMetrics($node->id()));
  }

  /**
   * A 200 with a JSON error body is also an authentication failure.
   */
  public function testJsonSessionExpiredThrows(): void {
    $node = $this->createResource(1, 'a.example');
    $body = '{"success":false,"message":"Session Expired"}';
    $client = $this->mockClient(array_fill(0, 5, new Response(200, ['Content-Type' => 'application/json'], $body)));

    try {
      $this->service($client)->updateAll();
      $this->fail('Expected XdmodAuthenticationException.');
    }
    catch (XdmodAuthenticationException $e) {
      $this->assertSame(200, $e->getStatusCode());
      $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
    }
    $this->assertSame('old-metrics', $this->storedMetrics($node->id()));
  }

  /**
   * A JSON error that isn't about the token is not blamed on the token.
   *
   * It yields no data, so with only this resource the run still fails, but as
   * an empty result rather than an authentication failure.
   */
  public function testJsonNonAuthErrorIsNotAuthFailure(): void {
    $node = $this->createResource(1, 'a.example');
    $body = '{"success":false,"message":"Invalid filter value"}';
    $client = $this->mockClient(array_fill(0, 5, new Response(200, ['Content-Type' => 'application/json'], $body)));

    $this->expectException(XdmodEmptyResultException::class);
    try {
      $this->service($client)->updateAll();
    }
    finally {
      $this->assertSame('old-metrics', $this->storedMetrics($node->id()));
    }
  }

  /**
   * One resource failing with a 5xx does not stop the others.
   */
  public function testPartialFailureIsTolerated(): void {
    $bad = $this->createResource(1, 'bad.example');
    $good = $this->createResource(2, 'good.example');
    $client = $this->routedClient([
      1 => fn() => new Response(503),
      2 => fn() => $this->csvResponse(),
    ]);

    $this->service($client)->updateAll();

    $this->assertSame('old-metrics', $this->storedMetrics($bad->id()));
    $this->assertStringContainsString('"resource":"good.example"', $this->storedMetrics($good->id()));
    $this->assertNotEmpty(array_filter($this->logged, fn($m) => str_starts_with($m, 'warning: XDMoD query failed')));
  }

  /**
   * Every resource failing throws an empty-result exception.
   */
  public function testTotalFailureThrows(): void {
    $node = $this->createResource(1, 'a.example');
    $this->createResource(2, 'b.example');
    $client = $this->routedClient([
      1 => fn() => new Response(503),
      2 => fn() => new Response(503),
    ]);

    $this->expectException(XdmodEmptyResultException::class);
    try {
      $this->service($client)->updateAll();
    }
    finally {
      $this->assertSame('old-metrics', $this->storedMetrics($node->id()));
    }
  }

  /**
   * With no XDMoD-linked resources there is nothing to fail.
   */
  public function testNoResourcesDoesNotThrow(): void {
    $this->service($this->mockClient([]))->updateAll();
    $this->addToAssertionCount(1);
  }

}
