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
   * Valid labels, queue names and references produce no errors.
   *
   * @covers ::errors
   */
  public function testValidReferencesHaveNoErrors(): void {
    $nodeTypes = [
      ['label' => 'A100 node', 'queues' => ['gpu', 'gpu-debug']],
      ['label' => 'Standard', 'queues' => ['cpu']],
    ];
    $this->assertSame([], NodeTypeReferences::errors(['A100 node', 'Standard'], ['gpu', 'gpu-debug', 'cpu'], $nodeTypes));
  }

  /**
   * A label used twice is reported once.
   *
   * @covers ::errors
   */
  public function testDuplicateLabelIsReported(): void {
    $errors = NodeTypeReferences::errors(['A100 node', 'A100 node', 'A100 node', 'Other'], [], []);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('Node type label "A100 node"', $errors[0]);
  }

  /**
   * A queue name used twice is reported once.
   *
   * @covers ::errors
   */
  public function testDuplicateQueueNameIsReported(): void {
    $errors = NodeTypeReferences::errors([], ['gpu', 'cpu', 'gpu', 'gpu'], []);
    $this->assertSame(['Queue name "gpu" is used more than once.'], $errors);
  }

  /**
   * A node type naming a missing queue is reported with both names.
   *
   * @covers ::errors
   */
  public function testMissingQueueIsReported(): void {
    $errors = NodeTypeReferences::errors(
      ['A100'],
      ['gpu'],
      [['label' => 'A100', 'queues' => ['gpu', 'gpu-debug']]],
    );
    $this->assertSame(['Node type "A100" lists queue "gpu-debug", which does not exist on this resource.'], $errors);
  }

  /**
   * Blank labels, queue names and references are ignored.
   *
   * @covers ::errors
   */
  public function testBlankValuesAreIgnored(): void {
    $errors = NodeTypeReferences::errors(
      ['A', '', '  ', ''],
      ['q', '', ' '],
      [['label' => 'A', 'queues' => ['', '   ', 'q']]],
    );
    $this->assertSame([], $errors);
  }

  /**
   * Numeric-looking values are compared as strings.
   *
   * @covers ::errors
   */
  public function testNumericValues(): void {
    $this->assertSame([], NodeTypeReferences::errors(['1', '2'], ['1', '2'], [['label' => '1', 'queues' => ['2', '1']]]));
    $errors = NodeTypeReferences::errors(['1', '1', '2'], ['1'], [['label' => '2', 'queues' => ['3']]]);
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
    $nodeTypes = [['label' => 'A100 node ', 'queues' => [' gpu']]];
    $this->assertSame([], NodeTypeReferences::errors([' A100 node'], ['gpu '], $nodeTypes));
    $this->assertCount(1, NodeTypeReferences::errors(['A', ' A'], [], []));
    $this->assertCount(1, NodeTypeReferences::errors([], ['q', ' q'], []));
    // Case-sensitive.
    $this->assertCount(1, NodeTypeReferences::errors(['a'], ['q'], [['label' => 'a', 'queues' => ['Q']]]));
  }

}
