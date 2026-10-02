<?php

namespace Drupal\operations_cider\Exception;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Thrown when XDMoD rejects the API token.
 *
 * XDMoD signals a bad token two ways: an HTTP 401/403, or a 2xx response with
 * a JSON error body instead of CSV ("Session Expired"). Both are detected by
 * the named constructors below so the query methods share one definition.
 *
 * The message never includes request parameters, because the token travels as
 * the "Bearer" form field and must not reach logs or email.
 */
class XdmodAuthenticationException extends XdmodException {

  /**
   * The Drupal key that holds the XDMoD token.
   */
  public const KEY_NAME = 'xdmod_api';

  /**
   * HTTP status codes that mean the token was refused.
   */
  protected const AUTH_STATUSES = [401, 403];

  /**
   * XDMoD error messages that mean the token was refused.
   */
  protected const AUTH_MESSAGE_PATTERN = '/session expired|token|authenticat|unauthori[sz]ed|not logged in/i';

  /**
   * Longest XDMoD error message carried into logs and email.
   */
  protected const MAX_DETAIL_LENGTH = 200;

  /**
   * The HTTP status, if there was a response.
   *
   * @var int|null
   */
  protected ?int $statusCode;

  /**
   * The name of the key holding the token.
   *
   * @var string
   */
  protected string $keyName;

  /**
   * Constructs an XdmodAuthenticationException.
   *
   * @param int|null $status_code
   *   The HTTP status of the rejecting response.
   * @param string $detail
   *   What XDMoD said (for example "Session Expired"), or an empty string.
   * @param string $key_name
   *   The name of the key holding the token.
   * @param \Throwable|null $previous
   *   The underlying exception, if any.
   */
  public function __construct(?int $status_code, string $detail = '', string $key_name = self::KEY_NAME, ?\Throwable $previous = NULL) {
    $this->statusCode = $status_code;
    $this->keyName = $key_name;
    $message = sprintf(
      'XDMoD rejected the API token (key: %s, HTTP %s)',
      $key_name,
      $status_code ?? 'unknown'
    );
    if ($detail !== '') {
      $message .= ': ' . $detail;
    }
    parent::__construct($message, $status_code ?? 0, $previous);
  }

  /**
   * Gets the HTTP status of the rejecting response.
   *
   * @return int|null
   *   The status, or NULL when unknown.
   */
  public function getStatusCode(): ?int {
    return $this->statusCode;
  }

  /**
   * Gets the name of the key holding the rejected token.
   *
   * @return string
   *   The key name.
   */
  public function getKeyName(): string {
    return $this->keyName;
  }

  /**
   * Builds an exception from a failed request, if it was an auth failure.
   *
   * @param \GuzzleHttp\Exception\RequestException $e
   *   The exception Guzzle threw (this includes ClientException).
   *
   * @return self|null
   *   The exception to throw, or NULL when the failure is something else
   *   (5xx, connect, timeout) that the caller should treat as transient.
   */
  public static function fromRequestException(RequestException $e): ?self {
    $response = $e->getResponse();
    if (!$response || !in_array($response->getStatusCode(), self::AUTH_STATUSES, TRUE)) {
      return NULL;
    }
    $detail = self::errorDetail((string) $response->getBody()) ?? $response->getReasonPhrase();
    return new self($response->getStatusCode(), $detail, self::KEY_NAME, $e);
  }

  /**
   * Builds an exception from a 2xx response whose body is an XDMoD auth error.
   *
   * Successful data responses are CSV, so a JSON body with "success":false is
   * an error that XDMoD reported without an error status. Only one whose
   * message is about the session or token counts: anything else (a bad filter
   * on one resource, say) is left to the caller's empty-result handling, so it
   * neither aborts the whole run nor sends an email blaming the token.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response to inspect.
   *
   * @return self|null
   *   The exception to throw, or NULL when the body is not an auth error.
   */
  public static function fromResponse(ResponseInterface $response): ?self {
    $body = trim((string) $response->getBody());
    if ($body === '' || $body[0] !== '{') {
      return NULL;
    }
    $json = json_decode($body, TRUE);
    if (!is_array($json)) {
      return NULL;
    }
    $detail = self::errorDetail($body);
    if ($detail === NULL || !preg_match(self::AUTH_MESSAGE_PATTERN, $detail)) {
      return NULL;
    }
    return new self($response->getStatusCode(), $detail);
  }

  /**
   * Extracts XDMoD's "message" from a JSON error body.
   *
   * @param string $body
   *   The raw response body.
   *
   * @return string|null
   *   The message, cut to MAX_DETAIL_LENGTH since it ends up in logs and
   *   email, or NULL when the body has none.
   */
  protected static function errorDetail(string $body): ?string {
    $json = json_decode($body, TRUE);
    if (is_array($json) && isset($json['message']) && is_string($json['message'])) {
      return mb_substr($json['message'], 0, self::MAX_DETAIL_LENGTH);
    }
    return NULL;
  }

}
