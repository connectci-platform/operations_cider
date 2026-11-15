<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\operations_cider\Controller\ResourceDocumentationController;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * Covers populated_sections on GET /api/resource-groups.
 *
 * The list must agree with the detail endpoint: sections inherited from the
 * group count, and a section counts only when the detail endpoint would return
 * content for it.
 *
 * @group operations_cider
 */
class ResourceGroupListTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'link', 'file', 'key',
    'entity_reference_revisions', 'paragraphs', 'operations_cider',
  ];

  /**
   * Node field => [type, paragraph bundle or NULL].
   */
  private const NODE_FIELDS = [
    'field_rp_ssh_login_nodes' => ['entity_reference_revisions', 'rp_ssh_login'],
    'field_rp_file_transfer' => ['entity_reference_revisions', 'rp_file_transfer'],
    'field_rp_datasets' => ['entity_reference_revisions', 'rp_dataset'],
    'field_rp_top_software' => ['string_long', NULL],
    'field_rp_ondemand_url' => ['link', NULL],
    'field_rp_login_text' => ['string_long', NULL],
    'field_rp_login_help_links' => ['link', NULL],
    'field_rp_storage' => ['entity_reference_revisions', 'rp_storage_filesystem'],
    'field_rp_queue_specs' => ['entity_reference_revisions', 'rp_queue_spec'],
    'field_rp_file_transfer_text' => ['string_long', NULL],
    'field_rp_storage_text' => ['string_long', NULL],
    'field_rp_external_storage' => ['string_long', NULL],
    'field_rp_datasets_text' => ['string_long', NULL],
    'field_rp_jobs_info' => ['string_long', NULL],
  ];

  /**
   * Paragraph bundle => string field holding its content.
   */
  private const PARAGRAPH_FIELDS = [
    'rp_ssh_login' => 'field_rp_ssh_hostname',
    'rp_file_transfer' => 'field_rp_method',
    'rp_dataset' => 'field_rp_dataset_name',
    'rp_storage_filesystem' => 'field_rp_directory',
    'rp_queue_spec' => 'field_rp_queue_name',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
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

    // The list echoes these identifiers for each member variant.
    foreach (['field_cider_resource_id', 'field_access_global_resource_id'] as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'access_active_resources_from_cid',
      ])->save();
    }

    foreach (self::PARAGRAPH_FIELDS as $bundle => $field) {
      ParagraphsType::create(['id' => $bundle, 'label' => $bundle])->save();
      FieldStorageConfig::create([
        'field_name' => $field,
        'entity_type' => 'paragraph',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $field,
        'entity_type' => 'paragraph',
        'bundle' => $bundle,
      ])->save();
    }
    // The datasets paragraph has a second field so "all empty" is meaningful.
    FieldStorageConfig::create([
      'field_name' => 'field_rp_dataset_description',
      'entity_type' => 'paragraph',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_rp_dataset_description',
      'entity_type' => 'paragraph',
      'bundle' => 'rp_dataset',
    ])->save();

    foreach (self::NODE_FIELDS as $name => [$type, $paragraph_bundle]) {
      $storage = [
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
      ];
      if ($name === 'field_rp_login_help_links') {
        $storage['cardinality'] = -1;
      }
      if ($type === 'entity_reference_revisions') {
        $storage['cardinality'] = -1;
        $storage['settings'] = ['target_type' => 'paragraph'];
      }
      FieldStorageConfig::create($storage)->save();
      foreach (['access_active_resources_from_cid', 'resource_group'] as $bundle) {
        $config = [
          'field_name' => $name,
          'entity_type' => 'node',
          'bundle' => $bundle,
        ];
        if ($paragraph_bundle) {
          $config['settings'] = [
            'handler' => 'default:paragraph',
            'handler_settings' => [
              'target_bundles' => [$paragraph_bundle => $paragraph_bundle],
            ],
          ];
        }
        FieldConfig::create($config)->save();
      }
    }
  }

  /**
   * Build a paragraph reference value for a node field.
   */
  private function paragraph(string $bundle, array $values): array {
    $paragraph = Paragraph::create(['type' => $bundle] + $values);
    $paragraph->save();
    return [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
  }

  /**
   * Build an SSH login node paragraph reference.
   */
  private function ssh(string $host = 'login.example.edu'): array {
    return $this->paragraph('rp_ssh_login', ['field_rp_ssh_hostname' => $host]);
  }

  /**
   * Build a file transfer paragraph reference.
   */
  private function transfer(): array {
    return $this->paragraph('rp_file_transfer', ['field_rp_method' => 'Globus']);
  }

  /**
   * Create a member variant node.
   */
  private function resource(array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'access_active_resources_from_cid',
      'title' => 'Variant',
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Create a resource group owning the given member.
   */
  private function group(Node $member, array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'resource_group',
      'title' => 'Group',
      'status' => 1,
      'field_cider_resources' => [['target_id' => $member->id()]],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Call the controller and return the decoded body and the response.
   *
   * @return array
   *   A two-item list: the decoded body and the response object.
   */
  private function list(): array {
    $response = ResourceDocumentationController::create($this->container)->listResourceGroups();
    return [json_decode($response->getContent(), TRUE), $response];
  }

  /**
   * Return populated_sections of the single group in the response.
   */
  private function sections(): array {
    [$body] = $this->list();
    $this->assertSame(1, $body['count']);
    return $body['groups'][0]['populated_sections'];
  }

  /**
   * Sections the group supplies reach a member that has none of its own.
   */
  public function testInheritedSectionsAreReported(): void {
    $resource = $this->resource();
    $this->group($resource, [
      'field_rp_ssh_login_nodes' => [$this->ssh()],
      'field_rp_file_transfer' => [$this->transfer()],
    ]);

    $this->assertSame(['login', 'file_transfer'], $this->sections());
  }

  /**
   * A member's own value counts when the group has none.
   */
  public function testMemberOwnValueCounts(): void {
    $resource = $this->resource(['field_rp_file_transfer' => [$this->transfer()]]);
    $this->group($resource);

    $this->assertSame(['file_transfer'], $this->sections());
  }

  /**
   * A paragraph whose mapped fields are all empty is not content.
   */
  public function testEmptyDatasetParagraphIsNotReported(): void {
    $empty = $this->paragraph('rp_dataset', []);
    $resource = $this->resource(['field_rp_datasets' => [$empty]]);
    $this->group($resource);

    $this->assertSame([], $this->sections());
  }

  /**
   * An empty JSON list of top software is not content.
   */
  public function testEmptyTopSoftwareJsonIsNotReported(): void {
    $resource = $this->resource(['field_rp_top_software' => '[]']);
    $this->group($resource);

    $this->assertSame([], $this->sections());

    // The group's cached entity reference would still hold the old variant.
    $resource->set('field_rp_top_software', '[{"name":"gcc"}]')->save();
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    $this->assertSame(['top_software'], $this->sections());
  }

  /**
   * An OnDemand URL alone makes the login section populated.
   */
  public function testOnDemandUrlAloneReportsLogin(): void {
    $resource = $this->resource([
      'field_rp_ondemand_url' => ['uri' => 'https://ondemand.example.edu', 'title' => ''],
    ]);
    $this->group($resource);

    $this->assertSame(['login'], $this->sections());
  }

  /**
   * Login text on the group alone reaches a member with no login of its own.
   */
  public function testGroupLoginTextAloneReportsLogin(): void {
    $resource = $this->resource();
    $this->group($resource, ['field_rp_login_text' => 'Use your ACCESS credentials.']);

    $this->assertSame(['login'], $this->sections());
  }

  /**
   * Login help links on the group alone make the login section populated.
   */
  public function testGroupLoginHelpLinksAloneReportsLogin(): void {
    $resource = $this->resource();
    $this->group($resource, [
      'field_rp_login_help_links' => [['uri' => 'https://help.example.edu', 'title' => 'Help']],
    ]);

    $this->assertSame(['login'], $this->sections());
  }

  /**
   * A storage paragraph on the group reaches a member with none.
   */
  public function testInheritedStorageIsReported(): void {
    $resource = $this->resource();
    $this->group($resource, [
      'field_rp_storage' => [$this->paragraph('rp_storage_filesystem', ['field_rp_directory' => 'Home'])],
    ]);

    $this->assertSame(['storage'], $this->sections());
  }

  /**
   * A member's own queue spec rows count.
   *
   * The queue specs field is not in INHERITABLE_FIELDS, so unlike the other
   * sections it can only come from the variant itself.
   */
  public function testMemberQueueSpecsAreReported(): void {
    $resource = $this->resource([
      'field_rp_queue_specs' => [$this->paragraph('rp_queue_spec', ['field_rp_queue_name' => 'gpu'])],
    ]);
    $this->group($resource);

    $this->assertSame(['queue_specs'], $this->sections());
  }

  /**
   * Queue specs set only on the group are not inherited, so not reported.
   */
  public function testGroupQueueSpecsAreNotInherited(): void {
    $resource = $this->resource();
    $this->group($resource, [
      'field_rp_queue_specs' => [$this->paragraph('rp_queue_spec', ['field_rp_queue_name' => 'gpu'])],
    ]);

    $this->assertSame([], $this->sections());
  }

  /**
   * Prose fields count as their section, inherited from the group or not.
   *
   * @dataProvider proseFieldProvider
   */
  public function testProseFieldReportsSection(string $field, string $section, bool $inherited): void {
    if ($inherited) {
      $resource = $this->resource();
      $this->group($resource, [$field => 'Some documentation text.']);
    }
    else {
      $resource = $this->resource([$field => 'Some documentation text.']);
      $this->group($resource);
    }

    $this->assertSame([$section], $this->sections());
  }

  /**
   * Data provider for testProseFieldReportsSection().
   */
  public static function proseFieldProvider(): array {
    return [
      'file transfer text' => ['field_rp_file_transfer_text', 'file_transfer', TRUE],
      'storage text' => ['field_rp_storage_text', 'storage', TRUE],
      'external storage' => ['field_rp_external_storage', 'storage', TRUE],
      'datasets text' => ['field_rp_datasets_text', 'datasets', TRUE],
      // Not in INHERITABLE_FIELDS, so it must be on the variant itself.
      'jobs info (own)' => ['field_rp_jobs_info', 'queue_specs', FALSE],
    ];
  }

  /**
   * Whitespace-only prose is not content.
   */
  public function testWhitespaceOnlyProseIsNotReported(): void {
    $resource = $this->resource([
      'field_rp_storage_text' => "  \n\t ",
      'field_rp_jobs_info' => '   ',
    ]);
    $this->group($resource, ['field_rp_datasets_text' => ' ']);

    $this->assertSame([], $this->sections());
  }

  /**
   * Sections from several variants are unioned, deduped, in SECTION_ORDER.
   */
  public function testVariantUnionInSectionOrder(): void {
    $variant_a = $this->resource([
      'field_rp_datasets' => [$this->paragraph('rp_dataset', ['field_rp_dataset_name' => 'Census'])],
      'field_rp_ssh_login_nodes' => [$this->ssh('a.example.edu')],
    ]);
    $variant_b = $this->resource(['field_rp_ssh_login_nodes' => [$this->ssh('b.example.edu')]]);
    $this->group($variant_a, [
      'field_cider_resources' => [
        ['target_id' => $variant_a->id()],
        ['target_id' => $variant_b->id()],
      ],
    ]);

    $this->assertSame(['login', 'datasets'], $this->sections());
  }

  /**
   * The response is invalidated by edits to the group.
   */
  public function testCacheTagsIncludeGroup(): void {
    $resource = $this->resource();
    $group = $this->group($resource);

    [, $response] = $this->list();
    $tags = $response->getCacheableMetadata()->getCacheTags();
    $this->assertContains('node:' . $group->id(), $tags);
    $this->assertContains('node:' . $resource->id(), $tags);
  }

}
