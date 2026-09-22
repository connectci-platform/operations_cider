<?php

namespace Drupal\Tests\operations_cider\Unit;

use Drupal\operations_cider\ResourceContentHash;
use Drupal\Tests\UnitTestCase;

/**
 * @group operations_cider
 * @coversDefaultClass \Drupal\operations_cider\ResourceContentHash
 */
class ResourceContentHashTest extends UnitTestCase {

  /** Host-varying top-level `url` and `last_modified` must not affect the hash. */
  public function testHashIgnoresVolatileKeys(): void {
    $a = ['title' => 'X', 'description' => 'D', 'last_modified' => '2026-01-01T00:00:00+00:00', 'url' => 'https://host-a/r'];
    $b = ['title' => 'X', 'description' => 'D', 'last_modified' => '2026-09-09T00:00:00+00:00', 'url' => 'https://host-b/r'];
    $this->assertSame(ResourceContentHash::hash($a), ResourceContentHash::hash($b));
  }

  /** org_url is stable content (not built with absolute=>TRUE) and IS hashed. */
  public function testOrgUrlIsHashedAsContent(): void {
    $a = ['title' => 'X', 'org_url' => 'https://example.edu/a'];
    $b = ['title' => 'X', 'org_url' => 'https://example.edu/b'];
    $this->assertNotSame(ResourceContentHash::hash($a), ResourceContentHash::hash($b));
  }

  /** A paragraph url-ish field with a different key name is NOT stripped. */
  public function testParagraphUrlFieldsAreNotStripped(): void {
    $a = ['ssh_logins' => [['hostname' => 'h', 'docs_url' => 'https://x/1']]];
    $b = ['ssh_logins' => [['hostname' => 'h', 'docs_url' => 'https://x/2']]];
    $this->assertNotSame(ResourceContentHash::hash($a), ResourceContentHash::hash($b));
  }

  /**
   * A metrics-free payload must keep hashing to the same literal value.
   *
   * The nested metrics strip must be inert for payloads that carry no metrics,
   * because a change there would silently invalidate every stored fingerprint
   * and make consumers re-ingest a corpus that did not change. A literal
   * expected value is the point: it survives refactors of the strip logic in a
   * way a self-comparison cannot.
   */
  public function testMetricsFreePayloadHashIsStable(): void {
    $payload = [
      'title' => 'Test Resource Alpha',
      'description' => 'A resource.',
      'queue_specs' => [
        ['name' => 'gpu', 'max_nodes' => 4],
        ['name' => 'cpu', 'max_nodes' => 8],
      ],
      'storage' => [['directory' => 'Home', 'quota_size' => 50]],
      'last_modified' => '2026-09-21T00:00:00+00:00',
      'url' => 'https://host/resource/1',
    ];
    $this->assertSame(
      '53809a078d4862c34b29dbf710d7a9cb56af6780ab37360ccb39948649a01ee6',
      ResourceContentHash::hash($payload),
      'The fingerprint of a metrics-free payload changed. This value was taken '
      . 'from the code as it stood before queue metrics existed, so a failure '
      . 'here means every stored fingerprint just became stale and every '
      . 'consumer will re-ingest once.'
    );
  }

  /** Nightly queue metrics must NOT churn the hash. */
  public function testQueueMetricsAreNotHashed(): void {
    $monday = [
      'title' => 'X',
      'queue_metrics_updated' => '2026-09-20',
      'queue_metrics' => ['gpu' => ['job_count' => 100, 'wait_time' => 3.5]],
    ];
    $tuesday = [
      'title' => 'X',
      'queue_metrics_updated' => '2026-09-21',
      'queue_metrics' => ['gpu' => ['job_count' => 212, 'wait_time' => 9.1]],
    ];
    $this->assertSame(ResourceContentHash::hash($monday), ResourceContentHash::hash($tuesday));
  }

  /** Stripping the metrics object must not take the queue specs with it. */
  public function testQueueSpecContentIsStillHashed(): void {
    $a = [
      'queue_specs' => [['name' => 'gpu', 'max_nodes' => 4]],
      'queue_metrics' => ['gpu' => ['job_count' => 1]],
    ];
    $b = [
      'queue_specs' => [['name' => 'gpu', 'max_nodes' => 8]],
      'queue_metrics' => ['gpu' => ['job_count' => 1]],
    ];
    $this->assertNotSame(ResourceContentHash::hash($a), ResourceContentHash::hash($b));
  }

  /**
   * Nested content objects are hashed, so documentation links reach the digest.
   *
   * Named for what this can actually prove: the hash treats an unlisted nested
   * object as content. That documentation_links IS such an object is the
   * controller's doing, and the kernel test covers that half — this class has
   * no knowledge of the key, so a test here cannot pin the feature's existence.
   */
  public function testNestedContentObjectsAreHashed(): void {
    $a = ['documentation_links' => ['ssh_login' => 'https://example.edu/ssh', 'software' => NULL]];
    $b = ['documentation_links' => ['ssh_login' => 'https://example.edu/ssh-v2', 'software' => NULL]];
    $this->assertNotSame(ResourceContentHash::hash($a), ResourceContentHash::hash($b));
  }

  /** Content changes MUST change the hash (positive). */
  public function testHashReflectsContentFields(): void {
    $base = ['title' => 'X', 'description' => 'D', 'storage' => [['directory' => 'Home']]];
    $changed = ['title' => 'X', 'description' => 'D CHANGED', 'storage' => [['directory' => 'Home']]];
    $this->assertNotSame(ResourceContentHash::hash($base), ResourceContentHash::hash($changed));
  }

  /** List order is meaningful and MUST affect the hash. */
  public function testListOrderMatters(): void {
    $first = ['ssh_logins' => [['hostname' => 'a'], ['hostname' => 'b']]];
    $swapped = ['ssh_logins' => [['hostname' => 'b'], ['hostname' => 'a']]];
    $this->assertNotSame(ResourceContentHash::hash($first), ResourceContentHash::hash($swapped));
  }

  /** Map key order must NOT affect the hash (canonicalization). */
  public function testMapKeyOrderIsCanonicalized(): void {
    $one = ['title' => 'X', 'description' => 'D'];
    $two = ['description' => 'D', 'title' => 'X'];
    $this->assertSame(ResourceContentHash::hash($one), ResourceContentHash::hash($two));
  }

  /** The 'url' member inside link entries is stripped (host-varying). */
  public function testLinkUrlsAreStripped(): void {
    $a = ['support_links' => [['title' => 'Guide', 'url' => 'https://host-a/g']]];
    $b = ['support_links' => [['title' => 'Guide', 'url' => 'https://host-b/g']]];
    $this->assertSame(ResourceContentHash::hash($a), ResourceContentHash::hash($b));
  }

}
