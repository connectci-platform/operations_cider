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
   * A 200 response with a CSV body.
   */
  protected function csvResponse(): Response {
    return new Response(200, ['Content-Type' => 'text/csv'], self::CSV);
  }

}
