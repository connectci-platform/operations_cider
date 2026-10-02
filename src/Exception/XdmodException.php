<?php

namespace Drupal\operations_cider\Exception;

/**
 * Base class for XDMoD sync failures that need a human to act.
 *
 * Catching this one type lets the cron wrapper alert on both an expired token
 * and an XDMoD that answers but returns nothing, without also alerting on
 * unrelated bugs (those still fail the job, but are not an XDMoD problem).
 */
class XdmodException extends \RuntimeException {

}
