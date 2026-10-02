<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\operations_cider\Exception\XdmodAuthenticationException;
use Drupal\operations_cider\Exception\XdmodEmptyResultException;
use Drupal\operations_cider\Plugin\CronManager;

/**
 * Covers CronManager's failure handling and alerting.
 *
 * @group operations_cider
 */
class CronManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'symfony_mailer', 'operations_cider'];

  /**
   * Replaces the queue metrics and alert services with stubs.
   *
   * @param \Throwable|null $failure
   *   What the stub queue metrics service throws, or NULL to succeed.
   *
   * @return object
   *   The spy, with a public $calls list of [label, exception] pairs.
   */
  protected function stub(?\Throwable $failure): object {
    $spy = new class() {

      /**
       * Recorded notify() calls.
       *
       * @var array
       */
      public array $calls = [];

      /**
       * Records a notification.
       */
      public function notify(string $label, \Throwable $e): void {
        $this->calls[] = [$label, $e];
      }

    };
    $metrics = new class($failure) {

      /**
       * Constructs the stub.
       */
      public function __construct(protected ?\Throwable $failure) {}

      /**
       * Throws the configured failure, if any.
       */
      public function updateAll(): void {
        if ($this->failure) {
          throw $this->failure;
        }
      }

    };
    $this->container->set('operations_cider.xdmod_alert', $spy);
    $this->container->set('operations_cider.queue_metrics', $metrics);
    return $spy;
  }

  /**
   * An XDMoD failure alerts, rethrows and leaves the last-run state alone.
   */
  public function testXdmodFailureNotifiesAndRethrows(): void {
    $state = $this->container->get('state');
    $state->set('operations_cider.queue_metrics_last_run', 111);
    $e = new XdmodAuthenticationException(401, 'Session Expired');
    $spy = $this->stub($e);

    try {
      CronManager::updateQueueMetrics();
      $this->fail('Expected the exception to be rethrown.');
    }
    catch (XdmodAuthenticationException $caught) {
      $this->assertSame($e, $caught);
    }
    $this->assertSame(111, $state->get('operations_cider.queue_metrics_last_run'));
    $this->assertCount(1, $spy->calls);
    $this->assertSame('Queue metrics', $spy->calls[0][0]);
    $this->assertSame($e, $spy->calls[0][1]);
  }

  /**
   * An empty-result failure alerts too.
   */
  public function testEmptyResultNotifies(): void {
    $spy = $this->stub(new XdmodEmptyResultException('nothing'));
    try {
      CronManager::updateQueueMetrics();
      $this->fail('Expected the exception to be rethrown.');
    }
    catch (XdmodEmptyResultException) {
    }
    $this->assertCount(1, $spy->calls);
  }

  /**
   * A generic failure is rethrown without an alert.
   */
  public function testGenericFailureDoesNotNotify(): void {
    $spy = $this->stub(new \RuntimeException('boom'));
    try {
      CronManager::updateQueueMetrics();
      $this->fail('Expected the exception to be rethrown.');
    }
    catch (\RuntimeException $caught) {
      $this->assertSame('boom', $caught->getMessage());
    }
    $this->assertSame([], $spy->calls);
  }

  /**
   * A throwing notifier does not replace the original exception.
   */
  public function testFailingNotifierDoesNotMaskOriginal(): void {
    $e = new XdmodAuthenticationException(401);
    $this->stub($e);
    $broken = new class() {

      /**
       * Always fails.
       */
      public function notify(string $label, \Throwable $e): void {
        throw new \LogicException('notifier down');
      }

    };
    $this->container->set('operations_cider.xdmod_alert', $broken);

    $this->expectException(XdmodAuthenticationException::class);
    CronManager::updateQueueMetrics();
  }

  /**
   * A successful run records the last-run time and sends no alert.
   */
  public function testSuccessSetsState(): void {
    $spy = $this->stub(NULL);
    CronManager::updateQueueMetrics();
    $this->assertNotNull($this->container->get('state')->get('operations_cider.queue_metrics_last_run'));
    $this->assertSame([], $spy->calls);
  }

}
