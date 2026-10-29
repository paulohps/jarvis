<?php

namespace App\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Firebase Cloud Messaging refused a call (status 400 or above), could not
 * be reached in time, or is not configured. `status` is the HTTP status, or
 * null when there was no answer at all; `errorCode` is the FCM error code
 * (`UNREGISTERED`, `INVALID_ARGUMENT`…) when FCM gave one.
 */
final class FcmException extends RuntimeException
{
    /**
     * The FCM error codes that mean the registration token is no good.
     */
    private const array REJECTED_TOKEN_CODES = ['UNREGISTERED', 'INVALID_ARGUMENT'];

    /**
     * @param  list<string>  $invalidFields  the fields a `google.rpc.BadRequest` detail blames
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly array $invalidFields = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * An FCM HTTP v1 error: `error.status` plus the `FcmError` and
     * `BadRequest` entries of `error.details`.
     */
    public static function fromResponse(string $action, Response $response): self
    {
        $errorCode = $response->json('error.status');
        $invalidFields = [];

        foreach ((array) $response->json('error.details', []) as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            if (isset($detail['errorCode']) && is_string($detail['errorCode'])) {
                $errorCode = $detail['errorCode'];
            }

            foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                if (is_array($violation) && isset($violation['field'])) {
                    $invalidFields[] = (string) $violation['field'];
                }
            }
        }

        $detail = $response->json('error.message') ?? $response->json('error_description') ?? $response->reason();

        return new self(
            sprintf('FCM %s failed with status %d: %s', $action, $response->status(), is_string($detail) ? $detail : $response->reason()),
            $response->status(),
            is_string($errorCode) ? $errorCode : null,
            $invalidFields,
        );
    }

    public static function unreachable(string $action, ConnectionException $exception): self
    {
        return new self(sprintf('FCM %s could not be reached: %s', $action, $exception->getMessage()), previous: $exception);
    }

    public static function notConfigured(string $reason): self
    {
        return new self('FCM is not configured: '.$reason);
    }

    /**
     * FCM rejected the registration token itself, so the device must stop
     * using it: `UNREGISTERED`, or `INVALID_ARGUMENT` unless FCM blames
     * another field of the message (a bad payload is our bug, not the
     * device's).
     */
    public function isTokenRejected(): bool
    {
        if (! in_array($this->errorCode, self::REJECTED_TOKEN_CODES, true)) {
            return false;
        }

        if ($this->errorCode === 'UNREGISTERED' || $this->invalidFields === []) {
            return true;
        }

        return in_array('message.token', $this->invalidFields, true);
    }
}
