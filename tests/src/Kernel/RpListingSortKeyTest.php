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
 * The view's configured sort is NULL for every resource_group row and no
 * single column holds a resource row's resolved label, so
 * operations_cider_views_pre_render() re-orders rows by
 * operations_cider_rp_listing_sort_key(). This locks the key resolution:
 * every row sorts by its displayed heading — the group title, or a
 * resource's label() (display name, short name, title).
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

  public function testResourceSortsByResolvedLabel(): void {
    // The bundle class resolves label() to the CiDeR short name, which is
    // what the listing heading shows for a resource row.
    $node = $this->makeResource('Long CiDeR Descriptive Title', 'Sage');
    $this->assertSame('Sage', operations_cider_rp_listing_sort_key($node));
  }

  public function testResourceWithoutShortNameSortsByTitle(): void {
    $node = $this->makeResource('Bare Resource', NULL);
    $this->assertSame('Bare Resource', operations_cider_rp_listing_sort_key($node));
  }

  public function testGroupSortsByItsOwnTitleNotItsMembers(): void {
    // The group heading is the group title; members render beneath it, so a
    // group titled "Test Resource Group" belongs under T even though its
    // first member is "Alpha".
    $alpha = $this->makeResource('Test Resource Alpha', 'Alpha');
    $group = $this->makeGroup('Test Resource Group', [$alpha]);
    $this->assertSame('Test Resource Group', operations_cider_rp_listing_sort_key($group));
  }

  public function testNullRowYieldsEmptyKey(): void {
    $this->assertSame('', operations_cider_rp_listing_sort_key(NULL));
  }

}
