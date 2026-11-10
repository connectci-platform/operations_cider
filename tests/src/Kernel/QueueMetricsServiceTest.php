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

  /**
   * Decodes the stored blob of a node.
   */
  protected function storedBlob(int $nid): array {
    return json_decode($this->storedMetrics($nid), TRUE);
  }

  /**
   * Asserts the parallel daily lists of every queue have the same length.
   */
  protected function assertDailyAligned(array $blob): void {
    foreach ($blob['queues'] as $name => $queue) {
      $this->assertCount(count($queue['daily_dates']), $queue['daily_wait'], "$name wait");
      $this->assertCount(count($queue['daily_dates']), $queue['daily_wall'], "$name wall");
      $sorted = $queue['daily_dates'];
      sort($sorted);
      $this->assertSame($sorted, $queue['daily_dates'], "$name dates ascending");
    }
  }

  /**
   * The last_data date is the last day XDMoD has rows for, not the fetch date.
   */
  public function testAnvilLastDataIsLastCsvRow(): void {
    $node = $this->createResource(3097, 'anvil.example');
    $this->service($this->fixtureClient([3097 => $this->anvilFixtures()]))->updateAll();

    $blob = $this->storedBlob($node->id());
    $this->assertSame('2026-10-19', $blob['last_data']);
    $this->assertSame(date('Y-m-d'), $blob['updated']);
    $this->assertNotSame($blob['updated'], $blob['last_data']);
    $this->assertDailyAligned($blob);
    $this->assertCount(5, $blob['queues']['wholenode']['daily_dates']);
    $this->assertSame('2026-10-19', end($blob['queues']['wholenode']['daily_dates']));
  }

  /**
   * Values land on the right queue although column order differs per CSV.
   */
  public function testColumnOrderDifferencesAreMatchedByQueueName(): void {
    $node = $this->createResource(3097, 'anvil.example');
    $this->service($this->fixtureClient([3097 => $this->anvilFixtures()]))->updateAll();

    $queues = $this->storedBlob($node->id())['queues'];
    $this->assertEquals(31.4847, $queues['wholenode']['wait_time']);
    $this->assertEquals(10.7457, $queues['wholenode']['wall_time']);
    $this->assertSame(5666, $queues['wholenode']['job_count']);
    $this->assertEquals(2.1, $queues['shared']['wall_time']);
    $this->assertSame(262958, $queues['shared']['job_count']);
    // Wait series lists wholenode first, wall series lists shared first.
    $this->assertEquals(22.92, $queues['wholenode']['daily_wait'][0]);
    $this->assertEquals(2.5, $queues['shared']['daily_wall'][0]);
    $this->assertEquals(11.1, $queues['wholenode']['daily_wall'][0]);
    $this->assertEquals(30.96, $queues['shared']['daily_wait'][0]);
    $this->assertEquals(0.28, $queues['debug']['daily_wall'][4]);
  }

  /**
   * Delta has no wait data: waits are NULL, walls are kept.
   */
  public function testDeltaZeroWaitsAreNull(): void {
    $node = $this->createResource(3031, 'delta.example');
    $this->service($this->fixtureClient([3031 => $this->deltaFixtures()]))->updateAll();

    $blob = $this->storedBlob($node->id());
    $this->assertDailyAligned($blob);
    foreach ($blob['queues'] as $name => $queue) {
      $this->assertNull($queue['wait_time'], "$name wait_time");
      $this->assertSame([NULL, NULL, NULL, NULL], $queue['daily_wait'], "$name daily_wait");
      $this->assertNotNull($queue['wall_time'], "$name wall_time");
    }
    // Wall data exists through the last row.
    $this->assertSame('2026-11-09', $blob['last_data']);
  }

  /**
   * A bare 0 daily wall is no data; a decimal zero stays a real zero.
   */
  public function testBareZeroWallIsNull(): void {
    $node = $this->createResource(3031, 'delta.example');
    $this->service($this->fixtureClient([3031 => $this->deltaFixtures()]))->updateAll();

    $queues = $this->storedBlob($node->id())['queues'];
    $this->assertSame([0.5, NULL, NULL, NULL], $queues['cpu-preempt']['daily_wall']);
    $this->assertEquals([1.3, 1.1, 1.2, 0.9], $queues['cpu']['daily_wall']);
  }

  /**
   * A bare 0 aggregate cell is NULL, not 0.0.
   */
  public function testBareZeroAggregateIsNull(): void {
    $fixtures = $this->deltaFixtures();
    $fixtures['avg_wallduration_hours|aggregate'] = $this->xdmodCsv(
      ['Wall Hours: Per Job'],
      ['cpu,1.20000000', 'cpu-preempt,0'],
      FALSE,
    );
    $node = $this->createResource(3031, 'delta.example');
    $this->service($this->fixtureClient([3031 => $fixtures]))->updateAll();

    $queues = $this->storedBlob($node->id())['queues'];
    $this->assertNull($queues['cpu-preempt']['wall_time']);
    $this->assertEquals(1.2, $queues['cpu']['wall_time']);
  }

}
