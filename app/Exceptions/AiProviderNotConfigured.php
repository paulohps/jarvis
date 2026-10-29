<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The remote voice interpreter has no AI provider connection to use
 * (US-2.3, US-10.2): no connection is active. A 503 whose message, "AI
 * provider not configured", the app shows and speaks.
 */
final class AiProviderNotConfigured extends HttpException
{
    public function __construct()
    {
        parent::__construct(503, __('AI provider not configured.'));
    }
}
