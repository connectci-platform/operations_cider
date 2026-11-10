<?php

namespace Drupal\Tests\operations_cider\Traits;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Log\AbstractLogger;

/**
 * Shared fixtures for the XDMoD service tests.
 *
 * Provides a Guzzle client backed by a MockHandler, a key repository holding a
 * known token, a logger that records messages, and resource nodes with the
 * fields the services read.
 */
trait XdmodTestTrait {

  /**
   * The token the stub key repository returns.
   */
  protected const TOKEN = 'secret-token-value-123';

  /**
   * A minimal XDMoD aggregate CSV with one queue.
   */
  protected const CSV = "title\n---------\nQueue,\"Stat\"\ngpu,1.5\n---------\n";

  /**
   * Messages recorded by the stub logger.
   *
   * @var string[]
   */
  protected array $logged = [];

  /**
   * Creates the node type and fields the services touch.
   */
  protected function createResourceFields(): void {
    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'Resource'])->save();
    $fields = [
      'field_rp_xdmod_resource_id' => 'integer',
      'field_access_global_resource_id' => 'string',
      'field_rp_queue_metrics' => 'string_long',
      'field_rp_top_software' => 'string_long',
    ];
    foreach ($fields as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'access_active_resources_from_cid',
      ])->save();
    }
  }

  /**
   * Creates a published resource node with a sentinel stored value.
   *
   * @param int $xdmod_id
   *   The XDMoD resource ID.
   * @param string $global_id
   *   The global resource ID.
   *
   * @return \Drupal\node\NodeInterface
   *   The node.
   */
  protected function createResource(int $xdmod_id, string $global_id) {
    $node = Node::create([
      'type' => 'access_active_resources_from_cid',
      'title' => $global_id,
      'status' => 1,
      'field_rp_xdmod_resource_id' => $xdmod_id,
      'field_access_global_resource_id' => $global_id,
      'field_rp_queue_metrics' => 'old-metrics',
      'field_rp_top_software' => 'old-software',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Builds a Guzzle client that serves the given queue of responses.
   *
   * @param array $queue
   *   Responses, exceptions or callables for the MockHandler.
   *
   * @return \GuzzleHttp\Client
   *   A client with the default http_errors middleware.
   */
  protected function mockClient(array $queue): Client {
    return new Client(['handler' => HandlerStack::create(new MockHandler($queue))]);
  }

  /**
   * Builds a handler that answers by the "resource_filter" form field.
   *
   * @param array $by_resource
   *   Callbacks keyed by XDMoD resource ID, each returning a Response.
   *
   * @return \GuzzleHttp\Client
   *   A client routing requests by resource.
   */
  protected function routedClient(array $by_resource): Client {
    $handler = function ($request) use ($by_resource) {
      parse_str((string) $request->getBody(), $form);
      return Create::promiseFor(
        $by_resource[(int) $form['resource_filter']]()
      );
    };
    return new Client(['handler' => HandlerStack::create($handler)]);
  }

  /**
   * Builds a key repository stub that returns the test token.
   */
  protected function keyRepository(): KeyRepositoryInterface {
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn(self::TOKEN);
    $repository = $this->createMock(KeyRepositoryInterface::class);
    $repository->method('getKey')->willReturn($key);
    return $repository;
  }

  /**
   * Builds a logger factory whose channel records into $this->logged.
   */
  protected function loggerFactory(): LoggerChannelFactoryInterface {
    $logger = new class($this->logged) extends AbstractLogger {

      /**
       * Constructs the recording logger.
       */
      public function __construct(protected array &$messages) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->messages[] = $level . ': ' . strtr((string) $message, $context);
      }

    };
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    return $factory;
  }

  /**
   * Builds an XDMoD CSV response body.
   *
   * @param string[] $header
   *   Column headers. For a timeseries, the queue columns after "Day", in
   *   XDMoD's "[queue] Label" form.
   * @param string[] $rows
   *   Raw CSV data lines.
   * @param bool $timeseries
   *   TRUE for a timeseries, FALSE for an aggregate.
   */
  protected function xdmodCsv(array $header, array $rows, bool $timeseries): string {
    $head = $timeseries ? 'Day,' : 'Queue,';
    return "title\n\"Stat\"\nparameters\n\"Resource =  Test\"\nstart,end\n2026-10-11,2026-11-10\n---------\n"
      . $head . implode(',', array_map(fn($h) => '"' . $h . '"', $header)) . "\n"
      . implode("\n", $rows) . "\n---------\n";
  }

  /**
   * Trimmed copies of the Anvil CPU CSVs (XDMoD resource 3097).
   *
   * XDMoD stops emitting rows after 2026-10-19. The wait series orders its
   * columns differently from the wall and job count ones.
   *
   * @return string[]
   *   CSV bodies keyed "statistic|dataset_type".
   */
  protected function anvilFixtures(): array {
    $wait = [
      '[wholenode] Wait Hours: Per Job',
      '[shared] Wait Hours: Per Job',
      '[debug] Wait Hours: Per Job',
    ];
    $wall = [
      '[shared] Wall Hours: Per Job',
      '[wholenode] Wall Hours: Per Job',
      '[debug] Wall Hours: Per Job',
    ];
    return [
      'avg_waitduration_hours|aggregate' => $this->xdmodCsv(
        ['Wait Hours: Per Job'],
        ['wholenode,31.48470000', 'shared,12.51234567', 'debug,0.60000000'],
        FALSE,
      ),
      'avg_wallduration_hours|aggregate' => $this->xdmodCsv(
        ['Wall Hours: Per Job'],
        ['shared,2.10000000', 'wholenode,10.74570000', 'debug,0.25000000'],
        FALSE,
      ),
      'job_count|aggregate' => $this->xdmodCsv(
        ['Number of Jobs Ended'],
        ['shared,262958', 'wholenode,5666', 'debug,949'],
        FALSE,
      ),
      'avg_waitduration_hours|timeseries' => $this->xdmodCsv($wait, [
        '2026-10-15,22.91879801,30.95660843,1.61820326',
        '2026-10-16,47.03543429,5.24002242,0.41551643',
        '2026-10-17,19.11594957,5.38780641,0.62587737',
        '2026-10-18,7.76950588,1.69389702,0.29344722',
        '2026-10-19,7.60215668,1.14019506,0.38607288',
      ], TRUE),
      'avg_wallduration_hours|timeseries' => $this->xdmodCsv($wall, [
        '2026-10-15,2.50000000,11.10000000,0.20000000',
        '2026-10-16,1.90000000,9.80000000,0.30000000',
        '2026-10-17,2.20000000,10.10000000,0.25000000',
        '2026-10-18,2.00000000,12.30000000,0.22000000',
        '2026-10-19,2.30000000,10.40000000,0.28000000',
      ], TRUE),
    ];
  }

  /**
   * Trimmed copies of the Delta CPU CSVs (XDMoD resource 3031).
   *
   * Delta reports no wait data (every wait is zero), and bare 0 cells mark
   * days a queue ran no jobs.
   *
   * @return string[]
   *   CSV bodies keyed "statistic|dataset_type".
   */
  protected function deltaFixtures(): array {
    $wait = ['[cpu] Wait Hours: Per Job', '[cpu-preempt] Wait Hours: Per Job'];
    $wall = ['[cpu-preempt] Wall Hours: Per Job', '[cpu] Wall Hours: Per Job'];
    return [
      'avg_waitduration_hours|aggregate' => $this->xdmodCsv(
        ['Wait Hours: Per Job'],
        ['cpu,0', 'cpu-preempt,0.00000000'],
        FALSE,
      ),
      'avg_wallduration_hours|aggregate' => $this->xdmodCsv(
        ['Wall Hours: Per Job'],
        ['cpu,1.20000000', 'cpu-preempt,0.80000000'],
        FALSE,
      ),
      'job_count|aggregate' => $this->xdmodCsv(
        ['Number of Jobs Ended'],
        ['cpu,180000', 'cpu-preempt,512'],
        FALSE,
      ),
      'avg_waitduration_hours|timeseries' => $this->xdmodCsv($wait, [
        '2026-11-06,0.00000000,0.00000000',
        '2026-11-07,0.00000000,0',
        '2026-11-08,0.00000000,0',
        '2026-11-09,0.00000000,0',
      ], TRUE),
      'avg_wallduration_hours|timeseries' => $this->xdmodCsv($wall, [
        '2026-11-06,0.50000000,1.30000000',
        '2026-11-07,0,1.10000000',
        '2026-11-08,0,1.20000000',
        '2026-11-09,0,0.90000000',
      ], TRUE),
    ];
  }

  /**
   * Builds a client answering by "statistic" and "dataset_type" form fields.
   *
   * @param array $by_resource
   *   Fixture maps (see anvilFixtures()) keyed by XDMoD resource ID.
   *
   * @return \GuzzleHttp\Client
   *   A client routing requests to the matching fixture.
   */
  protected function fixtureClient(array $by_resource): Client {
    $handler = function ($request) use ($by_resource) {
      parse_str((string) $request->getBody(), $form);
      $key = $form['statistic'] . '|' . $form['dataset_type'];
      return Create::promiseFor(
        new Response(
          200,
          ['Content-Type' => 'text/csv'],
          $by_resource[(int) $form['resource_filter']][$key],
        )
      );
    };
    return new Client(['handler' => HandlerStack::create($handler)]);
  }

  /**
   * A 200 response with a CSV body.
   */
  protected function csvResponse(): Response {
    return new Response(200, ['Content-Type' => 'text/csv'], self::CSV);
  }

}
