<?php

namespace Drupal\Tests\operations_cider\Unit;

use Drupal\operations_cider\NodeTypeReferences;
use Drupal\Tests\UnitTestCase;

/**
 * Tests validation of queue references to node type labels.
 *
 * @group operations_cider
 * @coversDefaultClass \Drupal\operations_cider\NodeTypeReferences
 */
class NodeTypeReferencesTest extends UnitTestCase {

  /**
   * Labels that exist and are referenced produce no errors.
   *
   * @covers ::errors
   */
  public function testValidReferencesHaveNoErrors(): void {
    $queues = [
      ['name' => 'gpu', 'node_types' => ['A100 node', 'H100 node']],
      ['name' => 'cpu', 'node_types' => ['Standard']],
    ];
    $this->assertSame([], NodeTypeReferences::errors(['A100 node', 'H100 node', 'Standard'], $queues));
  }

  /**
   * A label used twice is reported once.
   *
   * @covers ::errors
   */
  public function testDuplicateLabelIsReported(): void {
    $errors = NodeTypeReferences::errors(['A100 node', 'A100 node', 'A100 node', 'Other'], []);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('A100 node', $errors[0]);
  }

  /**
   * A queue pointing at a renamed node type names the queue and the label.
   *
   * @covers ::errors
   */
  public function testRenamedNodeTypeIsReported(): void {
    $errors = NodeTypeReferences::errors(
      ['H100 node'],
      [['name' => 'gpu', 'node_types' => ['A100 node']]],
    );
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('gpu', $errors[0]);
    $this->assertStringContainsString('A100 node', $errors[0]);
  }

  /**
   * Blank references and blank labels are ignored.
   *
   * @covers ::errors
   */
  public function testBlankReferenceIsIgnored(): void {
    $errors = NodeTypeReferences::errors(
      ['A', '', '  ', ''],
      [['name' => 'q', 'node_types' => ['', '   ', 'A']]],
    );
    $this->assertSame([], $errors);
  }

  /**
   * Numeric-looking labels are compared as strings.
   *
   * @covers ::errors
   */
  public function testNumericLabels(): void {
    $this->assertSame([], NodeTypeReferences::errors(['1', '2'], [['name' => 'q', 'node_types' => ['2', '1']]]));
    $errors = NodeTypeReferences::errors(['1', '1', '2'], [['name' => 'q', 'node_types' => ['3']]]);
    $this->assertCount(2, $errors);
    $this->assertStringContainsString('"1"', $errors[0]);
    $this->assertStringContainsString('"3"', $errors[1]);
  }

  /**
   * Surrounding whitespace is trimmed before comparing.
   *
   * @covers ::errors
   * @covers ::normalize
   */
  public function testWhitespaceIsTrimmed(): void {
    $this->assertSame('A100 node', NodeTypeReferences::normalize("  A100 node\t"));
    $this->assertSame([], NodeTypeReferences::errors([' A100 node'], [['name' => 'q', 'node_types' => ['A100 node ']]]));
    $this->assertCount(1, NodeTypeReferences::errors(['A', ' A'], []));
    // Case-sensitive.
    $this->assertCount(1, NodeTypeReferences::errors(['a'], [['name' => 'q', 'node_types' => ['A']]]));
  }

}
