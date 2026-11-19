<?php

namespace Drupal\Tests\operations_cider\Traits;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * Creates the resource node type and the queue and node type paragraph fields.
 *
 * For kernel tests that need field_rp_queue_specs and field_rp_node_types with
 * their paragraph bundles. The test class must enable the node, field, text,
 * file, options, entity_reference_revisions and paragraphs modules and install the
 * node, paragraph and file entity schemas. Everything is created only when it
 * does not exist yet, so a test that already defines some of these fields can
 * call this safely.
 */
trait NodeTypeFieldsTrait {

  /**
   * Creates the node type, paragraph bundles and every field they use.
   */
  protected function setUpNodeTypeFields(): void {
    $resource = 'access_active_resources_from_cid';
    if (!NodeType::load($resource)) {
      NodeType::create(['type' => $resource, 'name' => 'Resource'])->save();
    }

    foreach (['rp_queue_spec', 'rp_node_type', 'rp_accelerator', 'rp_node_type_queue'] as $bundle) {
      if (!ParagraphsType::load($bundle)) {
        ParagraphsType::create(['id' => $bundle, 'label' => $bundle])->save();
      }
    }

    $paragraphRef = [
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ];
    $int = ['type' => 'integer'];
    $string = ['type' => 'string'];

    $this->ensureNodeTypeField('node', 'field_rp_queue_specs', $paragraphRef, [$resource => ['rp_queue_spec']]);
    $this->ensureNodeTypeField('node', 'field_rp_node_types', $paragraphRef, [$resource => ['rp_node_type']]);

    $list = static fn (array $allowed) => [
      'type' => 'list_string',
      'settings' => ['allowed_values' => $allowed],
    ];

    $fields = [
      'field_rp_queue_name' => [$string, ['rp_queue_spec', 'rp_node_type_queue']],
      'field_rp_queue_purpose' => [$string, ['rp_queue_spec']],
      'field_rp_max_wall_time' => [$int, ['rp_queue_spec']],
      'field_rp_charged_per' => [
        $list(['core' => 'Core', 'gpu' => 'GPU', 'node' => 'Whole node']),
        ['rp_queue_spec'],
      ],
      'field_rp_queue_access' => [
        $list(['batch' => 'Batch', 'interactive' => 'Interactive only', 'by_request' => 'By request']),
        ['rp_queue_spec'],
      ],
      // Legacy hardware fields on the queue row, kept until the cleanup ticket.
      'field_rp_node_count' => [$int, ['rp_queue_spec', 'rp_node_type', 'rp_node_type_queue']],
      'field_rp_cpus' => [$string, ['rp_queue_spec', 'rp_node_type']],
      'field_rp_cpu_count' => [$int, ['rp_queue_spec', 'rp_node_type']],
      'field_rp_gpus' => [$string, ['rp_queue_spec']],
      'field_rp_gpu_count' => [$int, ['rp_queue_spec']],
      'field_rp_gpu_vram' => [$int, ['rp_queue_spec']],
      'field_rp_vram' => [$int, ['rp_queue_spec']],
      'field_rp_ram' => [$string, ['rp_queue_spec']],
      // Node type fields.
      'field_rp_node_type_label' => [$string, ['rp_node_type']],
      'field_rp_node_type_description' => [['type' => 'string_long'], ['rp_node_type']],
      'field_rp_cpu_architecture' => [
        $list(['x86_64' => 'x86_64', 'arm' => 'Arm']),
        ['rp_node_type'],
      ],
      'field_rp_node_ram' => [$int, ['rp_node_type']],
      'field_rp_accelerators' => [
        $paragraphRef,
        ['rp_node_type'],
        ['rp_node_type' => ['rp_accelerator']],
      ],
      'field_rp_node_type_queues' => [
        $paragraphRef,
        ['rp_node_type'],
        ['rp_node_type' => ['rp_node_type_queue']],
      ],
      // Accelerator fields.
      'field_rp_accelerator_type' => [
        $list(['gpu' => 'GPU', 'fpga' => 'FPGA', 'vector_engine' => 'Vector engine', 'other' => 'Other']),
        ['rp_accelerator'],
      ],
      'field_rp_accelerator_model' => [$string, ['rp_accelerator']],
      'field_rp_accelerator_count' => [$int, ['rp_accelerator']],
      'field_rp_accelerator_memory' => [$int, ['rp_accelerator']],
    ];
    foreach ($fields as $name => $spec) {
      $targets = [];
      foreach ($spec[1] as $bundle) {
        $targets[$bundle] = $spec[2][$bundle] ?? [];
      }
      $this->ensureNodeTypeField('paragraph', $name, $spec[0], $targets);
    }
  }

  /**
   * Creates a field storage and its instances when they are missing.
   *
   * @param string $entityType
   *   The entity type id.
   * @param string $name
   *   The field name.
   * @param array<string, mixed> $storage
   *   Extra storage definition: type, cardinality, settings.
   * @param array<string, list<string>> $bundles
   *   Bundles to attach to, each with the paragraph bundles it may reference.
   */
  private function ensureNodeTypeField(string $entityType, string $name, array $storage, array $bundles): void {
    if (!FieldStorageConfig::loadByName($entityType, $name)) {
      FieldStorageConfig::create($storage + [
        'field_name' => $name,
        'entity_type' => $entityType,
      ])->save();
    }
    foreach ($bundles as $bundle => $targets) {
      if (FieldConfig::loadByName($entityType, $bundle, $name)) {
        continue;
      }
      $config = [
        'field_name' => $name,
        'entity_type' => $entityType,
        'bundle' => $bundle,
      ];
      if ($targets) {
        $config['settings'] = [
          'handler' => 'default:paragraph',
          'handler_settings' => [
            'target_bundles' => array_combine($targets, $targets),
          ],
        ];
      }
      FieldConfig::create($config)->save();
    }
  }

}
