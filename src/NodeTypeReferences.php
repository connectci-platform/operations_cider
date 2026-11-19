<?php

namespace Drupal\operations_cider;

/**
 * Validates node type labels, queue names and node type queue references.
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
   * Finds duplicate labels, duplicate queue names and missing queue references.
   *
   * @param string[] $labels
   *   Node type labels in form order.
   * @param string[] $queueNames
   *   Queue names on the resource in form order.
   * @param array<int, array{label: string, queues: string[]}> $nodeTypeQueues
   *   Each node type's label with the queue names it lists.
   *
   * @return string[]
   *   Error messages: duplicate labels, duplicate queue names, then missing
   *   queue references in node type order. Empty when valid.
   */
  public static function errors(array $labels, array $queueNames, array $nodeTypeQueues): array {
    $errors = [];
    foreach (self::duplicates($labels) as $label) {
      $errors[] = sprintf('Node type label "%s" is used more than once.', $label);
    }
    foreach (self::duplicates($queueNames) as $name) {
      $errors[] = sprintf('Queue name "%s" is used more than once.', $name);
    }

    $known = [];
    foreach ($queueNames as $name) {
      $known[] = self::normalize((string) $name);
    }
    foreach ($nodeTypeQueues as $nodeType) {
      $label = self::normalize((string) $nodeType['label']);
      foreach ($nodeType['queues'] as $reference) {
        $reference = self::normalize((string) $reference);
        if ($reference === '' || in_array($reference, $known, TRUE)) {
          continue;
        }
        $errors[] = sprintf('Node type "%s" lists queue "%s", which does not exist on this resource.', $label, $reference);
      }
    }
    return $errors;
  }

  /**
   * Lists the non-blank values that occur more than once, each once.
   *
   * @param string[] $values
   *   The values.
   *
   * @return string[]
   *   The repeated values in first-repeat order.
   */
  private static function duplicates(array $values): array {
    $known = [];
    $reported = [];
    foreach ($values as $value) {
      $value = self::normalize((string) $value);
      if ($value === '') {
        continue;
      }
      if (in_array($value, $known, TRUE)) {
        if (!in_array($value, $reported, TRUE)) {
          $reported[] = $value;
        }
        continue;
      }
      $known[] = $value;
    }
    return $reported;
  }

}
