<?php

namespace App\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * The Evolution API refused a call (status 400 or above) or could not be
 * reached in time. `status` is the HTTP status, or null when there was no
 * answer at all.
 */
final class EvolutionApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(string $action, Response $response): self
    {
        $detail = $response->json('response.message') ?? $response->json('message') ?? $response->reason();

        return new self(
            sprintf('Evolution API %s failed with status %d: %s', $action, $response->status(), is_array($detail) ? implode(' ', Arr::flatten($detail)) : (string) $detail),
            $response->status(),
            $response->body(),
        );
    }

    public static function unreachable(string $action, ConnectionException $exception): self
    {
        return new self(sprintf('Evolution API %s could not be reached: %s', $action, $exception->getMessage()), previous: $exception);
    }

    /**
     * The instance does not exist on the Evolution API.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
