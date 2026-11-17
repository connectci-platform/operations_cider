<?php

namespace Drupal\operations_cider;

/**
 * Validates that queues only reference node types that exist on a resource.
 */
final class NodeTypeReferences {

  /**
   * Trims a node type label for comparison.
   *
   * @param string $label
   *   The raw label.
   *
   * @return string
   *   The label without surrounding whitespace.
   */
  public static function normalize(string $label): string {
    return trim($label);
  }

  /**
   * Finds duplicate node type labels and queue references to missing labels.
   *
   * @param string[] $labels
   *   Node type labels in form order.
   * @param array<int, array{name: string, node_types: string[]}> $queues
   *   Queues with the node type labels they reference.
   *
   * @return string[]
   *   Error messages, duplicates first, then queue references in queue order.
   *   Empty when valid.
   */
  public static function errors(array $labels, array $queues): array {
    $errors = [];
    $known = [];
    $reported = [];
    foreach ($labels as $label) {
      $label = self::normalize((string) $label);
      if ($label === '') {
        continue;
      }
      if (in_array($label, $known, TRUE)) {
        if (!in_array($label, $reported, TRUE)) {
          $reported[] = $label;
          $errors[] = sprintf('Node type label "%s" is used more than once.', $label);
        }
        continue;
      }
      $known[] = $label;
    }

    foreach ($queues as $queue) {
      $name = (string) $queue['name'];
      foreach ($queue['node_types'] as $reference) {
        $reference = self::normalize((string) $reference);
        if ($reference === '' || in_array($reference, $known, TRUE)) {
          continue;
        }
        $errors[] = sprintf('Queue "%s" lists node type "%s", which does not exist on this resource.', $name, $reference);
      }
    }
    return $errors;
  }

}
