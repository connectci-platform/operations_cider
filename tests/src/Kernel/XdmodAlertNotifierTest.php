<?php

namespace Drupal\Tests\operations_cider\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\operations_cider\Exception\XdmodAuthenticationException;
use Drupal\operations_cider\Exception\XdmodEmptyResultException;
use Drupal\operations_cider\Service\XdmodAlertNotifier;
use Drupal\symfony_mailer\EmailFactoryInterface;
use Drupal\symfony_mailer_test\MailerTestTrait;
use Drupal\filter\Entity\FilterFormat;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Covers the daily XDMoD failure alert.
 *
 * @group operations_cider
 */
class XdmodAlertNotifierTest extends KernelTestBase {

  use MailerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'key', 'filter', 'symfony_mailer', 'symfony_mailer_test', 'operations_cider',
  ];

  /**
   * The mutable current time, in seconds.
   *
   * @var int
   */
  protected int $now = 1780000000;

  /**
   * Cache tags recorded by the stub invalidator.
   *
   * @var string[]
   */
  protected array $invalidated = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['symfony_mailer']);
    $this->config('system.site')->set('mail', 'sender@example.com')->save();
    // The shipped mailer policy (config/optional) installs with the module;
    // its body uses this text format.
    FilterFormat::create(['format' => 'basic_html', 'name' => 'Basic HTML'])->save();
    Role::create(['id' => 'site_developer', 'label' => 'Site developer'])->save();
  }

  /**
   * Creates a user, optionally with the developer role.
   */
  protected function createUser(string $name, bool $developer, bool $active = TRUE): void {
    User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => $active ? 1 : 0,
      'roles' => $developer ? ['site_developer'] : [],
    ])->save();
  }

  /**
   * Builds a notifier with a controllable clock and environment.
   */
  protected function notifier(?string $env, ?EmailFactoryInterface $factory = NULL): XdmodAlertNotifier {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->method('invalidateTags')->willReturnCallback(function (array $tags) {
      $this->invalidated = array_merge($this->invalidated, $tags);
    });
    return new XdmodAlertNotifier(
      $factory ?? $this->container->get('email_factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.factory'),
      $this->container->get('state'),
      $time,
      $invalidator,
      $env ?? 'dev',
    );
  }

  /**
   * Counts emails sent so far.
   */
  protected function sentCount(): int {
    $this->emails = NULL;
    $this->init();
    return count($this->emails);
  }

  /**
   * Live: one email per developer, throttled to once a day.
   */
  public function testLiveSendsOncePerDay(): void {
    $this->createUser('dev1', TRUE);
    $this->createUser('dev2', TRUE);
    $this->createUser('inactive', TRUE, FALSE);
    $this->createUser('other', FALSE);
    $notifier = $this->notifier('live');
    $e = new XdmodAuthenticationException(401, 'Session Expired');

    $notifier->notify('Queue metrics', $e);
    $this->assertSame(2, $this->sentCount());
    $this->readMail(FALSE);
    $this->assertTo('dev1@example.com', 'dev1');
    $this->assertSubject('XDMoD sync failed: Queue metrics');
    $this->assertBodyContains('Queue metrics');
    $this->assertBodyContains('HTTP status: 401');
    $this->assertBodyContains('Session Expired');
    $this->readMail();
    $this->assertTo('dev2@example.com', 'dev2');
    $this->assertSame(date('Y-m-d', $this->now), $this->container->get('state')->get(XdmodAlertNotifier::STATE_KEY));

    // Same day, other job: nothing more.
    $notifier->notify('Top software', new XdmodEmptyResultException('none'));
    $this->assertSame(0, $this->sentCount());

    // Next day: sends again.
    $this->now += 86400;
    $notifier->notify('Top software', new XdmodEmptyResultException('none'));
    $this->assertSame(2, $this->sentCount());
  }

  /**
   * Not live: no email, but the resource cache tag is still invalidated.
   */
  public function testNonLiveOnlyInvalidates(): void {
    $this->createUser('dev1', TRUE);
    $this->notifier('dev')->notify('Queue metrics', new XdmodAuthenticationException(401));
    $this->assertSame(0, $this->sentCount());
    $this->assertSame([XdmodAlertNotifier::RESOURCE_CACHE_TAG], $this->invalidated);
  }

  /**
   * Live with no developers logs and does not fail.
   */
  public function testNoRecipients(): void {
    $this->createUser('other', FALSE);
    $this->notifier('live')->notify('Queue metrics', new XdmodAuthenticationException(401));
    $this->assertSame(0, $this->sentCount());
  }

  /**
   * A mail transport failure is logged and never thrown.
   */
  public function testMailFailureIsSwallowed(): void {
    $this->createUser('dev1', TRUE);
    $factory = $this->createMock(EmailFactoryInterface::class);
    $factory->method('newTypedEmail')->willThrowException(new \RuntimeException('smtp down'));

    $this->notifier('live', $factory)->notify('Queue metrics', new XdmodAuthenticationException(401));
    $this->assertSame([XdmodAlertNotifier::RESOURCE_CACHE_TAG], $this->invalidated);
  }

}
