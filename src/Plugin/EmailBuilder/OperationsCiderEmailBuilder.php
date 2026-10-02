<?php

namespace Drupal\operations_cider\Plugin\EmailBuilder;

use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\Processor\EmailBuilderBase;

/**
 * Email Builder plug-in for the operations_cider module.
 *
 * Subject and body come from the mailer policy
 * (symfony_mailer.mailer_policy.operations_cider.xdmod_error).
 *
 * @EmailBuilder(
 *   id = "operations_cider",
 *   sub_types = {
 *     "xdmod_error" = @Translation("XDMoD sync error"),
 *   },
 *   common_adjusters = {"email_subject", "email_body"},
 * )
 */
class OperationsCiderEmailBuilder extends EmailBuilderBase {

  /**
   * {@inheritdoc}
   */
  public function build(EmailInterface $email) {
    $email->setFrom('noreply@access-ci.org');
  }

}
