<?php

namespace App\Services\Slack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Slack answered a Web API call with `ok: false`, a 429, a status of 400
 * or above, or did not answer in time. `error` is Slack's error code
 * (`channel_not_found`, `ratelimited`, `token_revoked`…), null when there
 * was no answer; `retryAfter` the seconds Slack asked to wait when rate
 * limited.
 */
final class SlackApiException extends RuntimeException
{
    /**
     * The errors that mean the token is no good any more: the account
     * must be connected again.
     */
    public const array TOKEN_ERRORS = ['token_revoked', 'token_expired', 'invalid_auth', 'account_inactive', 'not_authed', 'missing_scope'];

    public function __construct(
        string $message,
        public readonly ?string $error = null,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(string $method, Response $response): self
    {
        $error = $response->json('error');
        $error = is_string($error) ? $error : null;

        if ($response->status() === 429 || $error === 'ratelimited') {
            $retryAfter = max(1, (int) $response->header('Retry-After'));

            return new self("Slack rate limited {$method}: retry after {$retryAfter}s.", 'ratelimited', $response->status(), $retryAfter);
        }

        $detail = $error ?? $response->reason();
        $needed = $response->json('needed');

        return new self(
            sprintf('Slack %s failed: %s%s', $method, $detail, is_string($needed) ? " (needed: {$needed})" : ''),
            $error ?? 'http_'.$response->status(),
            $response->status(),
        );
    }

    public static function unreachable(string $method, ConnectionException $exception): self
    {
        return new self(sprintf('Slack %s could not be reached: %s', $method, $exception->getMessage()), previous: $exception);
    }

    public function isRateLimited(): bool
    {
        return $this->error === 'ratelimited';
    }

    /**
     * The token no longer works: the account has to be connected again.
     */
    public function isTokenInvalid(): bool
    {
        return in_array($this->error, self::TOKEN_ERRORS, true);
    }

    public function isNotFound(): bool
    {
        return in_array($this->error, ['channel_not_found', 'message_not_found', 'user_not_found', 'file_not_found'], true);
    }
}
