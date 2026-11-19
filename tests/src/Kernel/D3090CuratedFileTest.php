<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\operations_cider\NodeTypeMigration;

/**
 * Checks the shipped curated file of the D8-3090 migration.
 *
 * @group operations_cider
 */
class D3090CuratedFileTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'link', 'key', 'file', 'options',
    'entity_reference_revisions', 'paragraphs', 'operations_cider',
  ];

  /**
   * Every curated entry validates and carries a title and a legacy hash.
   */
  public function testCuratedFileEntriesAreValid(): void {
    $migration = \Drupal::service('operations_cider.node_type_migration');
    $this->assertFileExists($migration->curatedPath());

    $entries = $migration->curated();
    $this->assertIsArray($entries);
    foreach ($entries as $globalId => $entry) {
      $this->assertIsString($globalId);
      $this->assertNotSame('', trim($globalId));
      $this->assertIsArray($entry, "$globalId is a map.");
      $this->assertNotEmpty($entry['title'] ?? NULL, "$globalId has a title.");
      $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($entry['legacy_hash'] ?? ''), "$globalId has a sha256 legacy_hash.");
      $errors = $migration->validate([
        'node_types' => $entry['node_types'] ?? NULL,
        'queues' => $entry['queues'] ?? NULL,
      ]);
      $this->assertSame([], $errors, "$globalId passes validate().");
    }
  }

  /**
   * The service reads the file the module ships.
   */
  public function testCuratedPathIsInsideModule(): void {
    $migration = \Drupal::service('operations_cider.node_type_migration');
    $this->assertInstanceOf(NodeTypeMigration::class, $migration);
    $this->assertStringEndsWith('operations_cider/migration/d3090_curated.yml', $migration->curatedPath());
  }

}
