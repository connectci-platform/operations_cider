<?php

namespace Drupal\operations_cider\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Enriches manually-entered OOD software names via SDS and caches JSON.
 *
 * Mirrors TopSoftwareService: reads field_rp_ood_software, enriches by name,
 * writes the enriched JSON back. When a future automated source provides the
 * app list, only the list source changes.
 */
class OodSoftwareService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The SDS enrichment service.
   *
   * @var \Drupal\operations_cider\Service\SdsEnrichmentService
   */
  protected SdsEnrichmentService $sds;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    SdsEnrichmentService $sds,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('operations_cider');
    $this->sds = $sds;
  }

  /**
   * Merge SDS enrichment into each entry by name; unmatched entries unchanged.
   */
  public function enrichEntries(array $entries, array $catalog): array {
    foreach ($entries as &$entry) {
      if (empty($entry['name'])) {
        continue;
      }
      $hit = $this->sds->findInSds($entry['name'], $catalog);
      if ($hit) {
        $entry = array_merge($entry, $this->sds->mapSdsFields($hit));
      }
    }
    unset($entry);
    return $entries;
  }

  /**
   * Sort by job_count desc when present; null/0 last preserving input order.
   */
  public function sortEntries(array $entries): array {
    // Stable sort: attach original index, compare by job_count then index.
    $indexed = [];
    foreach (array_values($entries) as $i => $e) {
      $indexed[] = ['e' => $e, 'i' => $i];
    }
    usort($indexed, function ($a, $b) {
      $ja = ($a['e']['job_count'] ?? -1);
      $jb = ($b['e']['job_count'] ?? -1);
      return ($jb <=> $ja) ?: ($a['i'] <=> $b['i']);
    });
    return array_map(fn($x) => $x['e'], $indexed);
  }

  /**
   * Enrich + cache OOD software JSON on all resources that have entries.
   */
  public function updateAll(): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'access_active_resources_from_cid')
      ->exists('field_rp_ood_software')
      ->accessCheck(FALSE)
      ->execute();
    foreach ($storage->loadMultiple($nids) as $node) {
      $raw = $node->get('field_rp_ood_software')->value;
      if (!$raw) {
        continue;
      }
      $entries = json_decode($raw, TRUE);
      if (!is_array($entries) || !$entries) {
        continue;
      }
      $names = array_filter(array_column($entries, 'name'));
      $catalog = $names ? $this->sds->fetchCatalogByNames(array_values($names)) : [];
      $enriched = $this->sortEntries($this->enrichEntries($entries, $catalog));
      $json = json_encode($enriched);
      if ($node->get('field_rp_ood_software')->value !== $json) {
        $node->set('field_rp_ood_software', $json);
        $node->save();
      }
    }
  }

}
