<?php

namespace Drupal\operations_cider\Exception;

/**
 * Thrown when every resource with an XDMoD ID came back empty.
 *
 * One resource returning nothing is normal (no jobs in the window, a transient
 * 5xx). All of them returning nothing means the sync is broken, even though no
 * individual request raised an error we recognize.
 */
class XdmodEmptyResultException extends XdmodException {

}
