<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\user\Entity\User;
use Drupal\Tests\operations_cider\Traits\NodeTypeFieldsTrait;

/**
 * Tests the resource edit form validation of node types and queues.
 *
 * Builds the real node form (the default operation, which carries the same
 * validator as the edit operation; the edit alter also needs resource group
 * fields this test does not create) with paragraphs widgets and submits it
 * programmatically, so the validator reads the same widget state and submitted
 * values it does in a browser.
 *
 * @group operations_cider
 */
class NodeTypeReferencesFormTest extends KernelTestBase {

  use NodeTypeFieldsTrait;

  private const BUNDLE = 'access_active_resources_from_cid';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'link', 'key', 'file', 'options',
    'entity_reference_revisions', 'paragraphs', 'operations_cider',
  ];

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
    $this->installConfig(['field']);
    // The node form formats the changed time with the short date format.
    foreach (['short', 'fallback'] as $id) {
      DateFormat::create(['id' => $id, 'label' => $id, 'pattern' => 'Y-m-d H:i'])->save();
    }
    DateFormat::create(['id' => 'html_date', 'label' => 'HTML Date', 'pattern' => 'Y-m-d'])->save();
    DateFormat::create(['id' => 'html_time', 'label' => 'HTML Time', 'pattern' => 'H:i:s'])->save();
    $this->setUpNodeTypeFields();
    $this->setUpFormDisplays('open');
    // The user with uid 1 bypasses access checks, so paragraph subforms are
    // not hidden from the programmatic submit.
    User::create(['uid' => 1, 'name' => 'admin'])->save();
    \Drupal::currentUser()->setAccount(User::load(1));
  }

  /**
   * Renaming a node type label needs no other edit.
   */
  public function testRenamingNodeTypeLabelSaves(): void {
    $node = $this->makeResource();
    $errors = $this->submit($node, $this->values($node, ['node_type_labels' => ['A100 GPU']]));
    $this->assertSame([], $errors);

    $node = $this->reload($node);
    $this->assertSame('A100 GPU', $this->nodeTypes($node)[0]->get('field_rp_node_type_label')->value);
    // The queue list of the node type is untouched.
    $this->assertSame(['gpu', 'cpu'], $this->listedQueues($this->nodeTypes($node)[0]));
  }

  /**
   * Renaming a queue that a node type lists is blocked, naming both.
   */
  public function testRenamingQueueWithoutUpdatingNodeTypesIsBlocked(): void {
    $node = $this->makeResource();
    $errors = $this->submit($node, $this->values($node, ['queue_names' => ['gpu-new', 'cpu']]));

    $this->assertCount(1, $errors);
    $this->assertStringContainsString('Node type "A100"', $errors[0]);
    $this->assertStringContainsString('queue "gpu"', $errors[0]);

    $node = $this->reload($node);
    $this->assertSame('gpu', $this->queues($node)[0]->get('field_rp_queue_name')->value);
  }

  /**
   * Renaming a queue and the node type entry that lists it saves.
   */
  public function testRenamingQueueAndNodeTypeEntryTogetherSaves(): void {
    $node = $this->makeResource();
    $errors = $this->submit($node, $this->values($node, [
      'queue_names' => ['gpu-new', 'cpu'],
      'listed' => [['gpu-new', 'cpu']],
    ]));
    $this->assertSame([], $errors);

    $node = $this->reload($node);
    $this->assertSame('gpu-new', $this->queues($node)[0]->get('field_rp_queue_name')->value);
    $this->assertSame(['gpu-new', 'cpu'], $this->listedQueues($this->nodeTypes($node)[0]));
  }

  /**
   * Duplicate queue names and node type labels block the save.
   */
  public function testDuplicatesAreBlocked(): void {
    $node = $this->makeResource(TRUE);
    $errors = $this->submit($node, $this->values($node, [
      'queue_names' => ['gpu', 'gpu'],
      'node_type_labels' => ['A100', 'A100'],
    ]));
    $messages = implode(' | ', $errors);
    $this->assertStringContainsString('Queue name "gpu" is used more than once', $messages);
    $this->assertStringContainsString('Node type label "A100" is used more than once', $messages);
  }

  /**
   * The same rules apply when the paragraphs are collapsed (closed).
   */
  public function testValidationWorksWithCollapsedParagraphs(): void {
    $this->setUpFormDisplays('closed');

    // Nothing submitted for the collapsed paragraphs: a clean node saves.
    $node = $this->makeResource();
    $this->assertSame([], $this->submit($node, ['title' => [0 => ['value' => 'Resource']]]));

    // A node type that lists a queue that no longer exists is still caught,
    // read from the paragraph entity rather than from submitted values.
    $node = $this->reload($node);
    $types = $this->nodeTypes($node);
    $entry = Paragraph::create(['type' => 'rp_node_type_queue', 'field_rp_queue_name' => 'ghost']);
    $entry->save();
    $types[0]->get('field_rp_node_type_queues')->appendItem($entry);
    $types[0]->save();
    $node->set('field_rp_node_types', $types);
    $node->save();

    $errors = $this->submit($this->reload($node), ['title' => [0 => ['value' => 'Resource']]]);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('Node type "A100"', $errors[0]);
    $this->assertStringContainsString('queue "ghost"', $errors[0]);
  }

  /**
   * Legacy hardware fields are hidden on queue rows once node types exist.
   */
  public function testLegacyHardwareFieldsHiddenWhenResourceHasNodeTypes(): void {
    $node = $this->makeResource();
    $subform = $this->builtQueueSubform($node);
    $this->assertArrayHasKey('field_rp_queue_name', $subform);
    foreach (['field_rp_cpus', 'field_rp_gpu_count'] as $field) {
      $this->assertArrayHasKey($field, $subform);
      $this->assertFalse($subform[$field]['#access'], "$field is hidden.");
    }
    $this->assertNotFalse($subform['field_rp_queue_name']['#access'] ?? TRUE);
  }

  /**
   * Legacy hardware fields stay editable on a resource without node types.
   */
  public function testLegacyHardwareFieldsVisibleWhenResourceHasNoNodeTypes(): void {
    $queue = Paragraph::create([
      'type' => 'rp_queue_spec',
      'field_rp_queue_name' => 'gpu',
      'field_rp_cpus' => 'AMD EPYC',
    ]);
    $queue->save();
    $node = Node::create([
      'type' => self::BUNDLE,
      'title' => 'Legacy',
      'field_rp_queue_specs' => [$queue],
    ]);
    $node->save();

    $subform = $this->builtQueueSubform($node);
    foreach (['field_rp_cpus', 'field_rp_gpu_count'] as $field) {
      $this->assertArrayHasKey($field, $subform);
      $this->assertNotFalse($subform[$field]['#access'] ?? TRUE, "$field is visible.");
    }
  }

  /**
   * Builds the node form and returns the first queue row's subform.
   *
   * @return array<string, mixed>
   *   The subform render array.
   */
  private function builtQueueSubform(Node $node): array {
    $form = \Drupal::service('entity.form_builder')->getForm($this->reload($node), 'default');
    $this->assertArrayHasKey('subform', $form['field_rp_queue_specs']['widget'][0]);
    return $form['field_rp_queue_specs']['widget'][0]['subform'];
  }

  /**
   * Creates the form displays with the paragraphs widget in the given mode.
   *
   * @param string $edit_mode
   *   Either 'open' (expanded) or 'closed' (collapsed).
   */
  private function setUpFormDisplays(string $edit_mode): void {
    $settings = [
      'title' => 'Title',
      'title_plural' => 'Titles',
      'edit_mode' => $edit_mode,
      'closed_mode' => 'summary',
      'autocollapse' => 'none',
      'closed_mode_threshold' => 0,
      'add_mode' => 'dropdown',
      'form_display_mode' => 'default',
      'default_paragraph_type' => '_none',
      'features' => ['duplicate' => 'duplicate', 'collapse_edit_all' => 'collapse_edit_all'],
    ];
    $paragraph_widget = ['type' => 'paragraphs', 'settings' => $settings, 'weight' => 0];

    $node_display = EntityFormDisplay::load('node.' . self::BUNDLE . '.default')
      ?? EntityFormDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => self::BUNDLE,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $node_display->setComponent('title', ['type' => 'string_textfield', 'weight' => -5]);
    $node_display->setComponent('field_rp_queue_specs', $paragraph_widget);
    $node_display->setComponent('field_rp_node_types', ['weight' => 1] + $paragraph_widget);
    $node_display->save();

    $plain = [
      'rp_queue_spec' => [
        'field_rp_queue_name' => 'string_textfield',
        'field_rp_queue_purpose' => 'string_textfield',
        'field_rp_cpus' => 'string_textfield',
        'field_rp_gpu_count' => 'number',
      ],
      'rp_node_type' => ['field_rp_node_type_label' => 'string_textfield'],
      'rp_node_type_queue' => ['field_rp_queue_name' => 'string_textfield', 'field_rp_node_count' => 'number'],
      'rp_accelerator' => ['field_rp_accelerator_model' => 'string_textfield'],
    ];
    foreach ($plain as $bundle => $components) {
      $display = EntityFormDisplay::load("paragraph.$bundle.default")
        ?? EntityFormDisplay::create([
          'targetEntityType' => 'paragraph',
          'bundle' => $bundle,
          'mode' => 'default',
          'status' => TRUE,
        ]);
      foreach ($components as $name => $type) {
        $display->setComponent($name, ['type' => $type]);
      }
      if ($bundle === 'rp_node_type') {
        $display->setComponent('field_rp_node_type_queues', $paragraph_widget);
      }
      $display->save();
    }
  }

  /**
   * Creates a resource with queues gpu and cpu and a node type listing both.
   *
   * @param bool $second_type
   *   Whether to add a second node type, "H100", listing the gpu queue.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved node.
   */
  private function makeResource(bool $second_type = FALSE): Node {
    $queues = [];
    foreach (['gpu', 'cpu'] as $name) {
      $queue = Paragraph::create(['type' => 'rp_queue_spec', 'field_rp_queue_name' => $name]);
      $queue->save();
      $queues[] = $queue;
    }
    $types = [];
    foreach ($second_type ? ['A100', 'H100'] : ['A100'] as $label) {
      $entries = [];
      foreach ($label === 'A100' ? ['gpu', 'cpu'] : ['gpu'] as $name) {
        $entry = Paragraph::create([
          'type' => 'rp_node_type_queue',
          'field_rp_queue_name' => $name,
          'field_rp_node_count' => 4,
        ]);
        $entry->save();
        $entries[] = $entry;
      }
      $type = Paragraph::create([
        'type' => 'rp_node_type',
        'field_rp_node_type_label' => $label,
        'field_rp_node_type_queues' => $entries,
      ]);
      $type->save();
      $types[] = $type;
    }
    $node = Node::create([
      'type' => self::BUNDLE,
      'title' => 'Resource',
      'field_rp_queue_specs' => $queues,
      'field_rp_node_types' => $types,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Builds submitted values for the expanded form, with optional overrides.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node whose current values are the base.
   * @param array<string, mixed> $override
   *   Optional keys: queue_names (list), node_type_labels (list) and listed
   *   (list of queue-name lists, one per node type).
   *
   * @return array<string, mixed>
   *   Form values.
   */
  private function values(Node $node, array $override = []): array {
    $values = ['title' => [0 => ['value' => $node->label()]]];

    $queue_names = $override['queue_names'] ?? array_map(static fn ($q) => $q->get('field_rp_queue_name')->value, $this->queues($node));
    foreach ($queue_names as $delta => $name) {
      $values['field_rp_queue_specs'][$delta] = [
        'subform' => ['field_rp_queue_name' => [0 => ['value' => $name]]],
        '_weight' => $delta,
      ];
    }

    foreach ($this->nodeTypes($node) as $delta => $type) {
      $label = $override['node_type_labels'][$delta] ?? $type->get('field_rp_node_type_label')->value;
      $listed = $override['listed'][$delta] ?? $this->listedQueues($type);
      $nested = [];
      foreach (array_values($listed) as $i => $name) {
        $nested[$i] = [
          'subform' => ['field_rp_queue_name' => [0 => ['value' => $name]]],
          '_weight' => $i,
        ];
      }
      $values['field_rp_node_types'][$delta] = [
        'subform' => [
          'field_rp_node_type_label' => [0 => ['value' => $label]],
          'field_rp_node_type_queues' => $nested,
        ],
        '_weight' => $delta,
      ];
    }
    return $values;
  }

  /**
   * Builds the edit form, submits the values and returns the form errors.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node being edited.
   * @param array<string, mixed> $values
   *   Submitted values.
   *
   * @return string[]
   *   The validation error messages, empty when the form saved.
   */
  private function submit(Node $node, array $values): array {
    $form_object = \Drupal::entityTypeManager()->getFormObject('node', 'default');
    $form_object->setEntity($node);
    $form_state = new FormState();
    $form_state->setValues($values);
    $form_state->setProgrammed(TRUE);
    $form_state->setProgrammedBypassAccessCheck(TRUE);
    $form_state->setTriggeringElement(['#type' => 'submit', '#value' => 'Save', '#submit' => ['::submitForm', '::save']]);
    \Drupal::formBuilder()->submitForm($form_object, $form_state);

    // Messages are HTML-escaped by t('@message').
    return array_map(static fn ($message) => html_entity_decode((string) $message), array_values($form_state->getErrors()));
  }

  /**
   * Reloads a node from storage.
   */
  private function reload(Node $node): Node {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  /**
   * The node's queue paragraphs.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph[]
   *   The paragraphs.
   */
  private function queues(Node $node): array {
    return array_values($node->get('field_rp_queue_specs')->referencedEntities());
  }

  /**
   * The node's node type paragraphs.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph[]
   *   The paragraphs.
   */
  private function nodeTypes(Node $node): array {
    return array_values($node->get('field_rp_node_types')->referencedEntities());
  }

  /**
   * Queue names a node type paragraph lists.
   *
   * @return string[]
   *   The names.
   */
  private function listedQueues(Paragraph $type): array {
    return array_map(
      static fn ($entry) => (string) $entry->get('field_rp_queue_name')->value,
      array_values($type->get('field_rp_node_type_queues')->referencedEntities())
    );
  }

}
