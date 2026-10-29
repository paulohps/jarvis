<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The invitation link no longer works (US-1.2): unknown token, or an
 * invitation that expired, was revoked or was already accepted. A 410 whose
 * error page says so; no account is created.
 */
final class InvalidInvitation extends HttpException
{
    public function __construct()
    {
        parent::__construct(410, 'The invitation is invalid, expired, revoked or already accepted.');
    }

    public static function make(): self
    {
        return new self;
    }
}
