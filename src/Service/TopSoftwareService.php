<?php

namespace Drupal\operations_cider\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches top software by combining XDMoD usage ranking with SDS metadata.
 *
 * XDMoD SUPREMM provides the ranking (which apps are most used per resource).
 * SDS provides the enrichment (descriptions, research fields, documentation).
 * The merged result is cached as JSON on each resource node.
 */
class TopSoftwareService {

  /**
   * XDMoD API endpoint.
   */
  const XDMOD_UI = 'https://xdmod.access-ci.org/controllers/user_interface.php';

  /**
   * Application names to exclude from XDMoD results.
   */
  const EXCLUDED_APPS = [
    'NA',
    'uncategorized',
    'PROPRIETARY',
    'unknown (htcondor)',
    'system applications',
    'a.out',
  ];

  protected ClientInterface $httpClient;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected $logger;
  protected KeyRepositoryInterface $keyRepository;
  protected SdsEnrichmentService $sds;

  /**
   * Constructs a TopSoftwareService.
   */
  public function __construct(
    ClientInterface $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    KeyRepositoryInterface $key_repository,
    SdsEnrichmentService $sds,
  ) {
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('operations_cider');
    $this->keyRepository = $key_repository;
    $this->sds = $sds;
  }

  /**
   * Fetch and cache top software for all resources with XDMoD IDs.
   */
  public function updateAll(): void {
    $xdmod_token = $this->keyRepository->getKey('xdmod_api')?->getKeyValue();
    if (!$xdmod_token) {
      $this->logger->error('XDMoD API token not configured (key: xdmod_api).');
      return;
    }

    $sds_key = $this->keyRepository->getKey('sds_api')?->getKeyValue();
    if (!$sds_key) {
      $this->logger->warning('SDS API key not configured (key: sds_api). Will proceed without SDS enrichment.');
    }

    $nodes = $this->loadResourceNodes();
    $end = date('Y-m-d');
    $start = date('Y-m-d', strtotime('-1 year'));
    $updated = 0;

    // Group nodes by XDMoD resource ID.
    $by_xdmod_id = [];
    foreach ($nodes as $node) {
      if (!$node->hasField('field_rp_xdmod_resource_id')
        || $node->get('field_rp_xdmod_resource_id')->isEmpty()) {
        continue;
      }
      $xdmod_id = (int) $node->get('field_rp_xdmod_resource_id')->value;
      $by_xdmod_id[$xdmod_id][] = $node;
    }

    foreach ($by_xdmod_id as $xdmod_id => $group_nodes) {
      // Step 1: Get ranked app list from XDMoD.
      $ranked = $this->fetchXdmodApps($xdmod_token, $xdmod_id, $start, $end);
      if ($ranked === NULL) {
        continue;
      }

      // Step 2: Enrich with SDS metadata if available.
      if ($sds_key) {
        // Try resource-specific SDS catalog first.
        $global_id = $group_nodes[0]
          ->get('field_access_global_resource_id')->value;
        $sds_catalog = [];
        if ($global_id) {
          $sds_catalog = $this->sds->fetchCatalogByResource($global_id);
        }
        // Fall back to name-based SDS lookup for any apps not found.
        $unenriched = array_filter($ranked, fn($e) => empty($e['description']));
        if (!empty($unenriched)) {
          $names = array_column($unenriched, 'name');
          $name_catalog = $this->sds->fetchCatalogByNames($names);
          $sds_catalog = array_merge($name_catalog, $sds_catalog);
        }
        $ranked = $this->enrichWithSds($ranked, $sds_catalog);
      }

      $json = json_encode($ranked);
      foreach ($group_nodes as $node) {
        if ($node->get('field_rp_top_software')->value === $json) {
          continue;
        }
        $node->set('field_rp_top_software', $json);
        $node->save();
        $updated++;
      }
    }

    $this->logger->notice('Updated top software for @count resources.', [
      '@count' => $updated,
    ]);
  }

  /**
   * Fetch top applications from XDMoD SUPREMM for a resource.
   *
   * @return array|null
   *   Array of ['name' => ..., 'job_count' => ...], or NULL.
   */
  protected function fetchXdmodApps(
    string $token,
    int $resource_id,
    string $start,
    string $end,
  ): ?array {
    try {
      $response = $this->httpClient->request('POST', self::XDMOD_UI, [
        'form_params' => [
          'operation' => 'get_data',
          'realm' => 'SUPREMM',
          'group_by' => 'application',
          'statistic' => 'job_count',
          'dataset_type' => 'aggregate',
          'format' => 'csv',
          'resource_filter' => (string) $resource_id,
          'start_date' => $start,
          'end_date' => $end,
          'Bearer' => $token,
        ],
        'timeout' => 30,
      ]);
      $csv = (string) $response->getBody();
    }
    catch (GuzzleException $e) {
      $this->logger->warning(
        'XDMoD SUPREMM query failed for resource @id: @message',
        ['@id' => $resource_id, '@message' => $e->getMessage()]
      );
      return NULL;
    }

    return $this->parseXdmodCsv($csv);
  }

  /**
   * Parse XDMoD SUPREMM application CSV into a ranked list.
   */
  protected function parseXdmodCsv(string $csv): ?array {
    $in_data = FALSE;
    $software = [];

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
      if (str_starts_with($line, 'Application,')) {
        continue;
      }
      $row = str_getcsv($line);
      if (count($row) < 2 || trim($row[0]) === '' || !is_numeric($row[1])) {
        continue;
      }

      $app_name = trim($row[0]);
      $job_count = (int) $row[1];

      if (in_array($app_name, self::EXCLUDED_APPS, TRUE)) {
        continue;
      }
      if (str_starts_with(strtolower($app_name), 'unknown')) {
        continue;
      }

      $software[] = [
        'name' => $app_name,
        'job_count' => $job_count,
      ];
    }

    if (empty($software)) {
      return NULL;
    }

    usort($software, fn($a, $b) => $b['job_count'] <=> $a['job_count']);

    return array_slice($software, 0, 10);
  }

  /**
   * Enrich XDMoD-ranked software list with SDS metadata.
   *
   * @param array $ranked
   *   XDMoD ranked list with 'name' and 'job_count'.
   * @param array $sds_catalog
   *   SDS catalog keyed by lowercase software name.
   *
   * @return array
   *   Enriched software list.
   */
  protected function enrichWithSds(array $ranked, array $sds_catalog): array {
    foreach ($ranked as &$entry) {
      $hit = $this->sds->findInSds($entry['name'], $sds_catalog);
      if ($hit) {
        $entry = array_merge($entry, $this->sds->mapSdsFields($hit));
      }
    }
    return $ranked;
  }

  /**
   * Load all published CiDeR resource nodes.
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
