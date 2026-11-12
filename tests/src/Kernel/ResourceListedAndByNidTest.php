<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\operations_cider\Controller\ResourceDocumentationController;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the `listed` flag and the by-nid detail endpoint.
 *
 * The `listed` flag mirrors views.view.rp_documentation_index: a resource is
 * listed when it, or a published resource group containing it, has
 * field_rp_listing set and field_domain_access including the documentation
 * domain.
 *
 * @group operations_cider
 */
class ResourceListedAndByNidTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'link', 'key', 'domain', 'file',
    'entity_reference_revisions', 'paragraphs', 'operations_cider',
  ];

  private const RESOURCE = 'access_active_resources_from_cid';
  private const DOMAIN = 'amp_cyberinfrastructure_org';
  private const OTHER_DOMAIN = 'other_example_org';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system']);

    foreach ([self::DOMAIN, self::OTHER_DOMAIN] as $i => $id) {
      \Drupal::entityTypeManager()->getStorage('domain')->create([
        'id' => $id,
        'hostname' => $id . '.example.com',
        'name' => $id,
        'weight' => $i,
      ])->save();
    }

    NodeType::create(['type' => self::RESOURCE, 'name' => 'Resource'])->save();
    NodeType::create(['type' => 'resource_group', 'name' => 'Resource Group'])->save();

    $this->addField('field_cider_resources', 'entity_reference', ['resource_group'], [
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ]);
    $this->addField('field_cider_resource_id', 'string', [self::RESOURCE]);
    $this->addField('field_rp_listing', 'boolean', [self::RESOURCE, 'resource_group']);
    $this->addField('field_domain_access', 'entity_reference', [self::RESOURCE, 'resource_group'], [
      'cardinality' => -1,
      'settings' => ['target_type' => 'domain'],
    ]);

    $this->addPayloadFields();

    // The endpoints are called directly, but accessCheck(TRUE) queries still
    // run as the current user, so give it the permission.
    Role::create(['id' => 'viewer', 'label' => 'Viewer'])
      ->grantPermission('access content')
      ->save();
    $user = User::create(['name' => 'viewer', 'status' => 1, 'roles' => ['viewer']]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Add every field the list and detail payloads read unguarded.
   */
  private function addPayloadFields(): void {
    $bundles = [self::RESOURCE];
    $types = [
      'string' => [
        'field_access_global_resource_id', 'field_access_org_name',
        'field_cider_latest_status', 'field_cider_resource_type',
        'field_cider_short_name',
      ],
      'text_long' => ['field_rp_description'],
      'boolean' => ['field_rp_mfa_required', 'field_rp_account_required'],
      'string_long' => [
        'field_rp_login_text', 'field_rp_file_transfer_text',
        'field_rp_storage_text', 'field_rp_datasets_text', 'field_rp_jobs_info',
        'field_rp_external_storage', 'field_rp_top_software',
        'field_rp_ood_software', 'field_rp_queue_metrics',
      ],
      'link' => [
        'field_access_org_url', 'field_rp_ondemand_url', 'field_rp_office_hours',
        'field_rp_software_list_url', 'field_rp_account_setup_url',
      ],
    ];
    foreach ($types as $type => $names) {
      foreach ($names as $name) {
        $this->addField($name, $type, $bundles);
      }
    }
    foreach (['field_rp_login_help_links', 'field_rp_support_links'] as $name) {
      $this->addField($name, 'link', $bundles, ['cardinality' => -1]);
    }
    // Paragraph fields are only iterated, so they stay empty in these tests.
    foreach ([
      'field_rp_ssh_login_nodes', 'field_rp_file_transfer', 'field_rp_storage',
      'field_rp_queue_specs', 'field_rp_datasets',
    ] as $name) {
      $this->addField($name, 'entity_reference_revisions', $bundles, [
        'cardinality' => -1,
        'settings' => ['target_type' => 'paragraph'],
      ]);
    }
  }

  /**
   * Add one field storage and its per-bundle configs.
   */
  private function addField(string $name, string $type, array $bundles, array $storage = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
    ] + $storage)->save();
    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => $bundle,
      ])->save();
    }
  }

  /**
   * Create a published resource node.
   */
  private function makeResource(string $title, array $values = []): Node {
    $node = Node::create($values + [
      'type' => self::RESOURCE,
      'title' => $title,
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Create a published resource group.
   */
  private function makeGroup(array $members, array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'resource_group',
      'title' => 'Group',
      'status' => 1,
      'field_cider_resources' => array_map(fn(Node $n) => ['target_id' => $n->id()], $members),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Get the controller under test.
   */
  private function controller(): ResourceDocumentationController {
    return ResourceDocumentationController::create($this->container);
  }

  /**
   * Get the `listed` value per nid from the list endpoint.
   */
  private function listedMap(): array {
    $request = Request::create('/api/1.0/resources', 'GET', ['documented' => 'false']);
    $data = json_decode($this->controller()->listResources($request)->getContent(), TRUE);
    $map = [];
    foreach ($data['resources'] as $row) {
      $this->assertIsBool($row['listed']);
      $map[$row['nid']] = $row['listed'];
    }
    return $map;
  }

  /**
   * Get the decoded detail payload for a node, asserting a 200.
   */
  private function detail(Node $node): array {
    $response = $this->controller()->getResourceByNid($node->id());
    $this->assertSame(200, $response->getStatusCode());
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * A resource flagged and on the domain is listed on its own.
   */
  public function testListedViaOwnFlag(): void {
    $own = $this->makeResource('Own', [
      'field_rp_listing' => 1,
      'field_domain_access' => [['target_id' => self::DOMAIN]],
    ]);
    $plain = $this->makeResource('Plain');

    $map = $this->listedMap();
    $this->assertTrue($map[$own->id()]);
    $this->assertFalse($map[$plain->id()]);
    $this->assertTrue($this->detail($own)['listed']);
    $this->assertFalse($this->detail($plain)['listed']);
  }

  /**
   * Own flag set but not on the documentation domain: not listed.
   */
  public function testOwnFlagWithoutDomainIsNotListed(): void {
    $wrong = $this->makeResource('Wrong domain', [
      'field_rp_listing' => 1,
      'field_domain_access' => [['target_id' => self::OTHER_DOMAIN]],
    ]);
    $none = $this->makeResource('No domain', ['field_rp_listing' => 1]);

    $map = $this->listedMap();
    $this->assertFalse($map[$wrong->id()]);
    $this->assertFalse($map[$none->id()]);
    $this->assertFalse($this->detail($wrong)['listed']);
  }

  /**
   * A member of a listed group on the domain is listed.
   */
  public function testListedViaListedGroup(): void {
    $member = $this->makeResource('Member');
    $this->makeGroup([$member], [
      'field_rp_listing' => 1,
      'field_domain_access' => [['target_id' => self::DOMAIN]],
    ]);

    $this->assertTrue($this->listedMap()[$member->id()]);
    $this->assertTrue($this->detail($member)['listed']);
  }

  /**
   * Unlisted, wrong-domain, or unpublished groups do not list their members.
   */
  public function testNotListedWhenGroupUnlisted(): void {
    $unflagged = $this->makeResource('Unflagged group member');
    $this->makeGroup([$unflagged], [
      'field_rp_listing' => 0,
      'field_domain_access' => [['target_id' => self::DOMAIN]],
    ]);
    $wrong_domain = $this->makeResource('Wrong domain group member');
    $this->makeGroup([$wrong_domain], [
      'field_rp_listing' => 1,
      'field_domain_access' => [['target_id' => self::OTHER_DOMAIN]],
    ]);
    $unpublished = $this->makeResource('Unpublished group member');
    $this->makeGroup([$unpublished], [
      'status' => 0,
      'field_rp_listing' => 1,
      'field_domain_access' => [['target_id' => self::DOMAIN]],
    ]);

    $map = $this->listedMap();
    foreach ([$unflagged, $wrong_domain, $unpublished] as $node) {
      $this->assertFalse($map[$node->id()], $node->label());
      $this->assertFalse($this->detail($node)['listed'], $node->label());
    }
  }

  /**
   * By-nid serves the same payload as by-resource-id, plus NULL-id resources.
   */
  public function testByNidMatchesByResourceId(): void {
    $node = $this->makeResource('With id', ['field_cider_resource_id' => 'test-alpha-1']);
    $by_id = $this->controller()->getResource('test-alpha-1');
    $by_nid = $this->controller()->getResourceByNid((string) $node->id());

    $this->assertSame(200, $by_nid->getStatusCode());
    $this->assertSame($by_id->getContent(), $by_nid->getContent());
    $this->assertSame($by_id->headers->get('ETag'), $by_nid->headers->get('ETag'));

    $no_id = $this->makeResource('No id');
    $data = $this->detail($no_id);
    $this->assertSame((int) $no_id->id(), $data['nid']);
    $this->assertNull($data['resource_id']);
  }

  /**
   * Group, unpublished, and nonexistent nids are 404s with the usual body.
   */
  public function testByNidNotFound(): void {
    $member = $this->makeResource('Member');
    $group = $this->makeGroup([$member]);
    $unpublished = $this->makeResource('Draft', ['status' => 0]);

    foreach ([$group->id(), $unpublished->id(), 999999] as $nid) {
      $response = $this->controller()->getResourceByNid($nid);
      $this->assertSame(404, $response->getStatusCode(), "nid $nid");
      $this->assertSame(['error' => 'Resource not found'], json_decode($response->getContent(), TRUE));
    }
  }

  /**
   * Both endpoints invalidate on resource group changes.
   */
  public function testResponsesCarryResourceGroupListCacheTag(): void {
    $node = $this->makeResource('Tagged', ['field_cider_resource_id' => 'test-tag-1']);

    $request = Request::create('/api/1.0/resources', 'GET', ['documented' => 'false']);
    $list = $this->controller()->listResources($request);
    $this->assertContains('node_list:resource_group', $list->getCacheableMetadata()->getCacheTags());

    $by_nid = $this->controller()->getResourceByNid($node->id());
    $this->assertSame(200, $by_nid->getStatusCode());
    $this->assertContains('node_list:resource_group', $by_nid->getCacheableMetadata()->getCacheTags());
  }

}
