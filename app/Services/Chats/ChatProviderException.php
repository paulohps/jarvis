<?php

namespace App\Services\Chats;

use RuntimeException;
use Throwable;

/**
 * A provider refused or could not do what a conversation asked (send,
 * react, load…): the screens show `getMessage()`, written for the user.
 * `previous` carries the provider's own exception for the report.
 */
final class ChatProviderException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
