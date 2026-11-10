<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\operations_cider\Service\ResourceGroupInheritanceService;

/**
 * Tests Resource Group -> Resource field inheritance.
 *
 * A Resource that leaves an inheritable field empty picks up its parent
 * Resource Group's value in memory; a Resource with its own value keeps it.
 * The parent link is a Group holding the Resource's nid in
 * field_cider_resources. Exercises the per-section source-documentation link
 * fields that were added to INHERITABLE_FIELDS.
 *
 * @group operations_cider
 * @coversDefaultClass \Drupal\operations_cider\Service\ResourceGroupInheritanceService
 */
class ResourceGroupInheritanceServiceTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'node', 'field', 'text', 'link', 'key', 'operations_cider'];

  /**
   * A representative inheritable link field used across the assertions.
   */
  private const LINK_FIELD = 'field_rp_software_link';

  /**
   * A second inheritable link field, to prove the list is honoured field-wise.
   */
  private const OTHER_LINK_FIELD = 'field_rp_storage_link';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'access_active_resources_from_cid', 'name' => 'Resource'])->save();
    NodeType::create(['type' => 'resource_group', 'name' => 'Resource Group'])->save();

    // The parent link: a Group references its member Resources here.
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

    // The inheritable link fields, present on BOTH bundles (as in production).
    foreach ([self::LINK_FIELD, self::OTHER_LINK_FIELD] as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'link',
      ])->save();
      foreach (['access_active_resources_from_cid', 'resource_group'] as $bundle) {
        FieldConfig::create([
          'field_name' => $name,
          'entity_type' => 'node',
          'bundle' => $bundle,
        ])->save();
      }
    }
  }

  private function service(): ResourceGroupInheritanceService {
    return \Drupal::service('operations_cider.resource_group_inheritance');
  }

  /**
   * Create a Resource node, optionally with its own link value.
   */
  private function makeResource(array $link_values = []): Node {
    $values = ['type' => 'access_active_resources_from_cid', 'title' => 'Res', 'status' => 1];
    foreach ($link_values as $field => $uri) {
      $values[$field] = ['uri' => $uri, 'title' => ''];
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * Create a Group owning the given Resource, optionally with link values.
   */
  private function makeGroup(Node $member, array $link_values = []): Node {
    $values = [
      'type' => 'resource_group',
      'title' => 'Group',
      'status' => 1,
      'field_cider_resources' => [['target_id' => $member->id()]],
    ];
    foreach ($link_values as $field => $uri) {
      $values[$field] = ['uri' => $uri, 'title' => ''];
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  private function linkUri(Node $node, string $field): ?string {
    return $node->get($field)->isEmpty() ? NULL : $node->get($field)->uri;
  }

  /**
   * @covers ::applyInheritance
   */
  public function testInheritsGroupValueWhenResourceEmpty(): void {
    $resource = $this->makeResource();
    $this->makeGroup($resource, [self::LINK_FIELD => 'https://group.example/software']);

    $this->service()->applyInheritance($resource);

    $this->assertSame('https://group.example/software', $this->linkUri($resource, self::LINK_FIELD));
  }

  /**
   * @covers ::applyInheritance
   */
  public function testResourceValueOverridesGroup(): void {
    $resource = $this->makeResource([self::LINK_FIELD => 'https://resource.example/own']);
    $this->makeGroup($resource, [self::LINK_FIELD => 'https://group.example/software']);

    $this->service()->applyInheritance($resource);

    $this->assertSame('https://resource.example/own', $this->linkUri($resource, self::LINK_FIELD));
  }

  /**
   * @covers ::applyInheritance
   */
  public function testEmptyGroupFieldLeavesResourceEmpty(): void {
    $resource = $this->makeResource();
    // Group exists and owns the resource, but its link field is unset.
    $this->makeGroup($resource);

    $this->service()->applyInheritance($resource);

    $this->assertNull($this->linkUri($resource, self::LINK_FIELD));
  }

  /**
   * @covers ::applyInheritance
   */
  public function testInheritanceIsPerFieldNotAllOrNothing(): void {
    // Resource sets ONE link itself and leaves the other empty; the group sets
    // both. The resource keeps its own for the first and inherits the second.
    $resource = $this->makeResource([self::LINK_FIELD => 'https://resource.example/own']);
    $this->makeGroup($resource, [
      self::LINK_FIELD => 'https://group.example/software',
      self::OTHER_LINK_FIELD => 'https://group.example/storage',
    ]);

    $this->service()->applyInheritance($resource);

    $this->assertSame('https://resource.example/own', $this->linkUri($resource, self::LINK_FIELD));
    $this->assertSame('https://group.example/storage', $this->linkUri($resource, self::OTHER_LINK_FIELD));
  }

  /**
   * An explicitly passed group is used instead of the looked-up parent.
   *
   * @covers ::applyInheritance
   */
  public function testExplicitGroupOverridesLookup(): void {
    $resource = $this->makeResource();
    // Group A owns the resource by reference; group B does not.
    $this->makeGroup($resource, [self::LINK_FIELD => 'https://group-a.example/software']);
    $other = Node::create([
      'type' => 'resource_group',
      'title' => 'Other group',
      'status' => 1,
      self::LINK_FIELD => ['uri' => 'https://group-b.example/software', 'title' => ''],
    ]);
    $other->save();

    $this->service()->applyInheritance($resource, $other);

    $this->assertSame('https://group-b.example/software', $this->linkUri($resource, self::LINK_FIELD));
  }

  /**
   * @covers ::findParentGroup
   * @covers ::applyInheritance
   */
  public function testResourceWithNoGroupIsUnchanged(): void {
    $resource = $this->makeResource();

    $this->assertNull($this->service()->findParentGroup($resource));
    $this->service()->applyInheritance($resource);
    $this->assertNull($this->linkUri($resource, self::LINK_FIELD));
  }

  /**
   * The source-doc link fields this change added are all in the inherit list.
   *
   * @covers ::applyInheritance
   */
  public function testSourceDocLinkFieldsAreInheritable(): void {
    $expected = [
      'field_rp_submitting_jobs',
      'field_rp_queue_spec_link',
      'field_rp_software_link',
      'field_rp_freq_used_software_link',
      'field_rp_storage_link',
      'field_rp_file_system_link',
      'field_rp_external_storage_link',
      'field_rp_file_transfer_link',
      'field_rp_ssh_login_link',
      'field_rp_login_to_anvil_gpu_link',
      'field_rp_datasets_link',
      'field_rp_access_ood_login_link',
    ];
    foreach ($expected as $field) {
      $this->assertContains($field, ResourceGroupInheritanceService::INHERITABLE_FIELDS, "$field should be inheritable");
    }
  }

}
