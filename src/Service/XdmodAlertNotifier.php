<?php

namespace Drupal\operations_cider\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\operations_cider\Exception\XdmodAuthenticationException;
use Drupal\operations_cider\Exception\XdmodEmptyResultException;
use Drupal\symfony_mailer\EmailFactoryInterface;

/**
 * Alerts site developers when an XDMoD sync job fails.
 *
 * Called from the cron wrapper just before it rethrows, so a broken XDMoD
 * token is noticed the day it breaks rather than when the metrics go stale.
 * Never throws: an alerting problem must not replace the job's own failure.
 */
class XdmodAlertNotifier {

  /**
   * State key holding the Y-m-d of the last alert.
   */
  public const STATE_KEY = 'operations_cider.xdmod_alert_last_sent';

  /**
   * Cache tag of the resource nodes that display the queue metrics.
   */
  public const RESOURCE_CACHE_TAG = 'node_list:access_active_resources_from_cid';

  /**
   * Role that receives the alert.
   */
  public const RECIPIENT_ROLE = 'site_developer';

  /**
   * What to do about an authentication failure.
   */
  public const RENEWAL_STEPS = 'Generate a new API token in XDMoD (My Profile → API Token) and replace private://.keys/xdmod-api.key on live, dev and test.';

  /**
   * The email factory, or NULL when symfony_mailer is not installed.
   *
   * @var \Drupal\symfony_mailer\EmailFactoryInterface|null
   */
  protected ?EmailFactoryInterface $emailFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * The Pantheon environment name, or NULL to read it from the environment.
   *
   * @var string|null
   */
  protected ?string $environment;

  /**
   * Constructs an XdmodAlertNotifier.
   *
   * @param \Drupal\symfony_mailer\EmailFactoryInterface|null $email_factory
   *   The email factory. The service definition references it as optional so
   *   the container still compiles where symfony_mailer is not enabled (such
   *   as kernel tests of unrelated services); email is then skipped.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator.
   * @param string|null $environment
   *   Environment name override for tests. NULL reads PANTHEON_ENVIRONMENT.
   */
  public function __construct(
    ?EmailFactoryInterface $email_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    TimeInterface $time,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
    ?string $environment = NULL,
  ) {
    $this->emailFactory = $email_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('operations_cider');
    $this->state = $state;
    $this->time = $time;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
    $this->environment = $environment;
  }

  /**
   * Alerts site developers about a failed XDMoD job, at most once a day.
   *
   * The daily limit is shared by both jobs: a dead token fails them both
   * nightly, and one email says it all.
   *
   * @param string $job_label
   *   Human-readable job name.
   * @param \Throwable $e
   *   The exception that failed the job.
   */
  public function notify(string $job_label, \Throwable $e): void {
    try {
      $today = date('Y-m-d', $this->time->getRequestTime());
      if ($this->state->get(self::STATE_KEY) === $today) {
        return;
      }

      // Resource pages compute "metrics are stale" at render time from the
      // stored refresh date, but render caches only change when a node does,
      // and a failing sync touches no node. Without this the page would keep
      // showing a cached "current" badge after the cutoff passed. It runs on
      // every environment (the cost is one cache-tag write, and dev and test
      // should reflect staleness too) but only once a day with the alert.
      $this->cacheTagsInvalidator->invalidateTags([self::RESOURCE_CACHE_TAG]);

      if ($this->getEnvironment() === 'live') {
        $this->sendEmails($job_label, $e);
      }

      // Recorded after the attempt, so a crash above retries tomorrow's run
      // rather than being silenced by a throttle that never alerted anyone.
      $this->state->set(self::STATE_KEY, $today);
    }
    catch (\Throwable $notify_error) {
      $this->logger->error('XDMoD alert failed: @message', [
        '@message' => $notify_error->getMessage(),
      ]);
    }
  }

  /**
   * Gets the Pantheon environment name.
   *
   * @return string|null
   *   The environment, or NULL when not running on Pantheon.
   */
  protected function getEnvironment(): ?string {
    if ($this->environment !== NULL) {
      return $this->environment;
    }
    $env = getenv('PANTHEON_ENVIRONMENT');
    return $env === FALSE ? NULL : $env;
  }

  /**
   * Emails every active site developer.
   *
   * @param string $job_label
   *   Human-readable job name.
   * @param \Throwable $e
   *   The exception that failed the job.
   */
  protected function sendEmails(string $job_label, \Throwable $e): void {
    if (!$this->emailFactory) {
      $this->logger->warning('XDMoD alert not sent: symfony_mailer is not available.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('user');
    $uids = $storage->getQuery()
      ->condition('status', 1)
      ->condition('roles', self::RECIPIENT_ROLE)
      ->accessCheck(FALSE)
      ->execute();
    $recipients = $uids ? $storage->loadMultiple($uids) : [];
    if (!$recipients) {
      $this->logger->warning('XDMoD alert not sent: no active users have the @role role.', [
        '@role' => self::RECIPIENT_ROLE,
      ]);
      return;
    }

    $variables = $this->buildVariables($job_label, $e);
    foreach ($recipients as $user) {
      if (!$user->getEmail()) {
        continue;
      }
      // One failing address must not stop the others.
      try {
        $email = $this->emailFactory->newTypedEmail('operations_cider', 'xdmod_error');
        foreach ($variables as $name => $value) {
          $email->setVariable($name, $value);
        }
        $email->setTo($user->getEmail());
        $email->send();
      }
      catch (\Throwable $mail_error) {
        $this->logger->error('Could not send XDMoD alert to user @uid: @message', [
          '@uid' => $user->id(),
          '@message' => $mail_error->getMessage(),
        ]);
      }
    }
  }

  /**
   * Builds the template variables for the alert email.
   *
   * @param string $job_label
   *   Human-readable job name.
   * @param \Throwable $e
   *   The exception that failed the job.
   *
   * @return array<string, string>
   *   Variables keyed by name: job, status, key, message and steps.
   */
  protected function buildVariables(string $job_label, \Throwable $e): array {
    $status = 'n/a';
    $key = XdmodAuthenticationException::KEY_NAME;
    $steps = self::RENEWAL_STEPS;
    if ($e instanceof XdmodAuthenticationException) {
      $status = (string) ($e->getStatusCode() ?? 'unknown');
      $key = $e->getKeyName();
    }
    elseif ($e instanceof XdmodEmptyResultException) {
      $steps = 'XDMoD returned no data for any resource. If the API token has expired, ' . self::RENEWAL_STEPS;
    }
    return [
      'job' => $job_label,
      'status' => $status,
      'key' => $key,
      'message' => $e->getMessage(),
      'steps' => $steps,
    ];
  }

}
