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
