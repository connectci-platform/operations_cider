<?php

namespace Drupal\operations_cider\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\operations_cider\Exception\XdmodAuthenticationException;
use Drupal\operations_cider\Exception\XdmodEmptyResultException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Fetches queue wait/wall time metrics from XDMoD and caches on nodes.
 *
 * Uses the authenticated XDMoD API (user_interface.php) with a Bearer token
 * from the Drupal key module. Queries per-queue wait and wall time for each
 * resource, plus a 30-day daily timeseries for sparklines.
 */
class QueueMetricsService {

  /**
   * XDMoD API endpoint.
   */
  const XDMOD_UI = 'https://xdmod.access-ci.org/controllers/user_interface.php';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * Constructs a QueueMetricsService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   */
  public function __construct(
    ClientInterface $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    KeyRepositoryInterface $key_repository,
  ) {
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('operations_cider');
    $this->keyRepository = $key_repository;
  }

  /**
   * Fetch and cache queue metrics for all resources.
   *
   * @throws \Drupal\operations_cider\Exception\XdmodAuthenticationException
   *   When XDMoD rejects the API token.
   * @throws \Drupal\operations_cider\Exception\XdmodEmptyResultException
   *   When every resource with an XDMoD ID came back empty.
   */
  public function updateAll(): void {
    $token = $this->keyRepository->getKey('xdmod_api')?->getKeyValue();
    if (!$token) {
      $this->logger->error('XDMoD API token not configured (key: xdmod_api).');
      return;
    }

    $nodes = $this->loadResourceNodes();
    $end = date('Y-m-d');
    $start = date('Y-m-d', strtotime('-30 days'));
    $updated = 0;
    $attempted = 0;
    $succeeded = 0;

    foreach ($nodes as $node) {
      if (!$node->hasField('field_rp_xdmod_resource_id')
        || $node->get('field_rp_xdmod_resource_id')->isEmpty()) {
        continue;
      }
      $xdmod_id = (int) $node->get('field_rp_xdmod_resource_id')->value;

      $attempted++;
      $data = $this->fetchQueueMetrics($token, $xdmod_id, $start, $end);
      if (empty($data)) {
        continue;
      }
      $succeeded++;

      $json = json_encode([
        'updated' => $end,
        'resource' => $node->get('field_access_global_resource_id')->value,
        'queues' => $data,
      ]);
      // Skip save if the data hasn't changed.
      if ($node->get('field_rp_queue_metrics')->value === $json) {
        continue;
      }
      $node->set('field_rp_queue_metrics', $json);
      $node->save();
      $updated++;
    }

    // One empty resource is normal; all of them empty means the sync is
    // broken. Nothing was saved above, so the stored metrics are untouched.
    if ($attempted > 0 && $succeeded === 0) {
      throw new XdmodEmptyResultException(sprintf(
        'XDMoD returned no queue metrics for any of the %d resources with an XDMoD ID.',
        $attempted
      ));
    }

    $this->logger->notice('Updated queue metrics for @count resources.', [
      '@count' => $updated,
    ]);
  }

  /**
   * Fetch per-queue wait and wall time for a resource.
   *
   * @param string $token
   *   The XDMoD API token.
   * @param int $resource_id
   *   The XDMoD numeric resource ID.
   * @param string $start
   *   Start date (YYYY-MM-DD).
   * @param string $end
   *   End date (YYYY-MM-DD).
   *
   * @return array
   *   Per-queue data keyed by queue name.
   */
  protected function fetchQueueMetrics(
    string $token,
    int $resource_id,
    string $start,
    string $end,
  ): array {
    $base_params = [
      'operation' => 'get_data',
      'realm' => 'Jobs',
      'group_by' => 'queue',
      'dataset_type' => 'aggregate',
      'format' => 'csv',
      'resource_filter' => (string) $resource_id,
      'start_date' => $start,
      'end_date' => $end,
      // XDMoD user_interface.php expects the token as a POST form field
      // named "Bearer", not as an HTTP Authorization header.
      'Bearer' => $token,
    ];

    // Fetch aggregate wait and wall times, plus job count.
    $wait_data = $this->queryXdmod(
      $base_params + ['statistic' => 'avg_waitduration_hours']
    );
    $wall_data = $this->queryXdmod(
      $base_params + ['statistic' => 'avg_wallduration_hours']
    );
    $job_count_data = $this->queryXdmod(
      $base_params + ['statistic' => 'job_count']
    );

    // Fetch daily timeseries for sparklines.
    $ts_params = $base_params;
    $ts_params['dataset_type'] = 'timeseries';
    $wait_ts = $this->queryXdmodTimeseries(
      $ts_params + ['statistic' => 'avg_waitduration_hours']
    );
    $wall_ts = $this->queryXdmodTimeseries(
      $ts_params + ['statistic' => 'avg_wallduration_hours']
    );

    // Merge into per-queue structure.
    $queues = [];
    $all_queue_names = array_unique(array_merge(
      array_keys($wait_data),
      array_keys($wall_data),
      array_keys($job_count_data)
    ));

    foreach ($all_queue_names as $queue) {
      $queues[$queue] = [
        'wait_time' => $wait_data[$queue] ?? NULL,
        'wall_time' => $wall_data[$queue] ?? NULL,
        'job_count' => isset($job_count_data[$queue]) ? (int) $job_count_data[$queue] : NULL,
        'daily_wait' => $wait_ts[$queue] ?? [],
        'daily_wall' => $wall_ts[$queue] ?? [],
      ];
    }

    return $queues;
  }

  /**
   * Query XDMoD aggregate data and parse CSV response.
   *
   * @param array $params
   *   POST form params.
   *
   * @return array
   *   Keyed by queue name, value is the float statistic.
   *
   * @throws \Drupal\operations_cider\Exception\XdmodAuthenticationException
   *   When XDMoD rejects the API token. Other request failures are logged and
   *   return an empty array.
   */
  protected function queryXdmod(array $params): array {
    try {
      $response = $this->httpClient->request('POST', self::XDMOD_UI, [
        'form_params' => $params,
        'timeout' => 30,
      ]);
      if ($auth_error = XdmodAuthenticationException::fromResponse($response)) {
        throw $auth_error;
      }
      $csv = (string) $response->getBody();
    }
    catch (GuzzleException $e) {
      if ($e instanceof RequestException && $auth_error = XdmodAuthenticationException::fromRequestException($e)) {
        throw $auth_error;
      }
      $this->logger->warning(
        'XDMoD query failed: @message',
        ['@message' => $e->getMessage()]
      );
      return [];
    }

    return $this->parseCsvAggregate($csv);
  }

  /**
   * Query XDMoD timeseries data and parse CSV response.
   *
   * @param array $params
   *   POST form params.
   *
   * @return array
   *   Keyed by queue name, value is array of daily floats.
   *
   * @throws \Drupal\operations_cider\Exception\XdmodAuthenticationException
   *   When XDMoD rejects the API token. Other request failures are logged and
   *   return an empty array.
   */
  protected function queryXdmodTimeseries(array $params): array {
    try {
      $response = $this->httpClient->request('POST', self::XDMOD_UI, [
        'form_params' => $params,
        'timeout' => 30,
      ]);
      if ($auth_error = XdmodAuthenticationException::fromResponse($response)) {
        throw $auth_error;
      }
      $csv = (string) $response->getBody();
    }
    catch (GuzzleException $e) {
      if ($e instanceof RequestException && $auth_error = XdmodAuthenticationException::fromRequestException($e)) {
        throw $auth_error;
      }
      $this->logger->warning(
        'XDMoD timeseries query failed: @message',
        ['@message' => $e->getMessage()]
      );
      return [];
    }

    return $this->parseCsvTimeseries($csv);
  }

  /**
   * Parse XDMoD aggregate CSV into queue → value map.
   *
   * CSV format:
   *   title\n"..."\nparameters\n"..."\nstart,end\n...\n---------\n
   *   Queue,"Stat Name"\nqueue1,value1\nqueue2,value2\n---------.
   *
   * @param string $csv
   *   Raw CSV response.
   *
   * @return array
   *   Keyed by queue name, value is the float.
   */
  protected function parseCsvAggregate(string $csv): array {
    $result = [];
    $in_data = FALSE;

    foreach (explode("\n", $csv) as $line) {
      $line = trim($line);
      if ($line === '---------') {
        if ($in_data) {
          break;
        }
        $in_data = TRUE;
        continue;
      }
      if (!$in_data) {
        continue;
      }
      // Skip the header row.
      if (str_starts_with($line, 'Queue,')) {
        continue;
      }
      $row = str_getcsv($line);
      if (count($row) >= 2 && trim($row[0]) !== '' && is_numeric($row[1])) {
        $result[trim($row[0])] = round((float) $row[1], 4);
      }
    }

    return $result;
  }

  /**
   * Parse XDMoD timeseries CSV into queue → daily values map.
   *
   * Timeseries CSV has columns: date, queue1, queue2, ...
   *
   * @param string $csv
   *   Raw CSV response.
   *
   * @return array
   *   Keyed by queue name, value is array of daily floats.
   */
  protected function parseCsvTimeseries(string $csv): array {
    $result = [];
    $in_data = FALSE;
    $headers = [];

    foreach (explode("\n", $csv) as $line) {
      $line = trim($line);
      if ($line === '---------') {
        if ($in_data) {
          break;
        }
        $in_data = TRUE;
        continue;
      }
      if (!$in_data) {
        continue;
      }
      $row = str_getcsv($line);
      if (empty($headers)) {
        // First row headers: Day, "[cpu] Wait Hours: Per Job", ...
        // Extract queue name from brackets. XDMoD timeseries CSV uses
        // the format "[queue_name] Metric Label" for column headers.
        $headers = $row;
        for ($i = 1; $i < count($headers); $i++) {
          if (preg_match('/^\[([^\]]+)\]/', $headers[$i], $m)) {
            $headers[$i] = $m[1];
          }
          $result[$headers[$i]] = [];
        }
        continue;
      }
      // Data rows: 2026-03-01, value1, value2, ...
      for ($i = 1; $i < count($row); $i++) {
        if (isset($headers[$i])) {
          $result[$headers[$i]][] = is_numeric($row[$i])
            ? round((float) $row[$i], 2)
            : 0;
        }
      }
    }

    return $result;
  }

  /**
   * Load all published CiDeR resource nodes.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Array of resource nodes.
   */
  protected function loadResourceNodes(): array {
    $nids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    return $nids
      ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids)
      : [];
  }

}
