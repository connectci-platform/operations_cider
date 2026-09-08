<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests the /rp-documentation listing sort key.
 *
 * The listing's rows are resource groups rendered as their member resources'
 * short names, so the view's SQL sort cannot match the displayed order;
 * operations_cider_views_pre_render() re-orders rows by
 * operations_cider_rp_listing_sort_key(). This locks the key resolution:
 * a group sorts by its alphabetically first member's resolved name, a
 * resource by its own short name, with label() fallbacks throughout.
 *
 * @group operations_cider
 */
class RpListingSortKeyTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'node', 'field', 'text', 'link', 'key', 'operations_cider'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'Resource'])->save();
    NodeType::create(['type' => 'resource_group', 'name' => 'Resource Group'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cider_resources',
      'entity_type' => 'node',
      'bundle' => 'resource_group',
      'settings' => ['handler' => 'default'],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_cider_short_name',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cider_short_name',
      'entity_type' => 'node',
      'bundle' => 'access_active_resources_from_cid',
    ])->save();

    require_once \Drupal::service('extension.list.module')->getPath('operations_cider') . '/operations_cider.module';
  }

  private function makeResource(string $title, ?string $short): Node {
    $values = ['type' => 'access_active_resources_from_cid', 'title' => $title, 'status' => 1];
    if ($short !== NULL) {
      $values['field_cider_short_name'] = $short;
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  private function makeGroup(string $title, array $members): Node {
    $node = Node::create([
      'type' => 'resource_group',
      'title' => $title,
      'status' => 1,
      'field_cider_resources' => array_map(fn($m) => ['target_id' => $m->id()], $members),
    ]);
    $node->save();
    return $node;
  }

  public function testResourceSortsByShortName(): void {
    $node = $this->makeResource('Long CiDeR Descriptive Title', 'Sage');
    $this->assertSame('Sage', operations_cider_rp_listing_sort_key($node));
  }

  public function testResourceFallsBackToLabel(): void {
    $node = $this->makeResource('Bare Resource', NULL);
    $this->assertSame('Bare Resource', operations_cider_rp_listing_sort_key($node));
  }

  public function testGroupSortsByFirstMemberAlphabetically(): void {
    // Delta order deliberately reversed from alphabetical order: the key must
    // come from the alphabetically first member, not delta 0. "OSG" showing
    // "OSPool" is the motivating case: the group's own title is irrelevant.
    $b = $this->makeResource('Title B', 'TAMU Launch');
    $a = $this->makeResource('Title A', 'PSC Neocortex CS');
    $group = $this->makeGroup('OSG', [$b, $a]);
    $this->assertSame('PSC Neocortex CS', operations_cider_rp_listing_sort_key($group));
  }

  public function testGroupMemberWithoutShortNameUsesLabel(): void {
    $m = $this->makeResource('Aardvark Cluster', NULL);
    $z = $this->makeResource('T', 'Zebra');
    $group = $this->makeGroup('Group', [$z, $m]);
    $this->assertSame('Aardvark Cluster', operations_cider_rp_listing_sort_key($group));
  }

  public function testEmptyGroupFallsBackToOwnLabel(): void {
    $group = $this->makeGroup('Lonely Group', []);
    $this->assertSame('Lonely Group', operations_cider_rp_listing_sort_key($group));
  }

  public function testNullRowYieldsEmptyKey(): void {
    $this->assertSame('', operations_cider_rp_listing_sort_key(NULL));
  }

}
