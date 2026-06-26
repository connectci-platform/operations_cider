<?php

namespace Drupal\operations_cider;

/**
 * Deterministic, host-independent content fingerprint for a resource payload.
 *
 * Pure (no Drupal deps) so the canonicalization rules are unit-testable. Strips
 * volatile keys via an explicit literal set (NEVER a pattern match, so a future
 * content field can't be accidentally swept up), recursively ksorts associative
 * arrays while leaving list arrays in order (list order is meaningful, e.g.
 * ssh_logins[0] is the recommended login), then hashes the JSON string. Hashing
 * the JSON (not the PHP array) keeps the result stable.
 */
final class ResourceContentHash {

  /**
   * Top-level keys excluded from the fingerprint.
   *
   * Only `url` is stripped: it is the node's canonical URL built with
   * `absolute => TRUE` (getResource() line ~130) and therefore varies by serving
   * host. The other URL-ish fields (`org_url`, `ondemand_url`, `office_hours`,
   * `software_list_url`, `account_setup_url`) come from getLinkValue() WITHOUT
   * the absolute flag — they are stored/external or host-relative and stable, so
   * they stay in the hash as content. `last_modified` is the timestamp we
   * explicitly want excluded.
   */
  private const STRIP_TOP_LEVEL = ['last_modified', 'url'];

  /**
   * Multi-value link-list fields whose entries carry a host-absolute `url`
   * member (from getMultiLinkValues()). We strip ONLY the `url` member of
   * entries in exactly these named fields — by path, not by heuristic — so a
   * future field that happens to contain a `url` key is never accidentally
   * stripped (correct by construction). The link `title` stays (it is content).
   */
  private const LINK_LIST_FIELDS = ['login_help_links', 'support_links'];

  public static function hash(array $data): string {
    $payload = array_diff_key($data, array_flip(self::STRIP_TOP_LEVEL));
    foreach (self::LINK_LIST_FIELDS as $field) {
      if (isset($payload[$field]) && is_array($payload[$field])) {
        foreach ($payload[$field] as $i => $entry) {
          if (is_array($entry)) {
            unset($payload[$field][$i]['url']);
          }
        }
      }
    }
    $canonical = self::canonicalize($payload);
    return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
  }

  private static function canonicalize($value) {
    if (!is_array($value)) {
      return $value;
    }
    $isList = array_keys($value) === range(0, count($value) - 1);
    $out = [];
    foreach ($value as $k => $v) {
      $out[$k] = self::canonicalize($v);
    }
    if (!$isList) {
      ksort($out);
    }
    return $out;
  }

}
