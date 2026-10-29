<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The user opened a module that is switched off for them (US-2.1): a 403
 * whose error page says so ("You don't have access to this module"). Thrown
 * by the module access middleware.
 */
final class ModuleAccessDenied extends HttpException
{
    public function __construct(public readonly string $moduleSlug)
    {
        parent::__construct(403, "Module [{$moduleSlug}] is switched off for this user.");
    }

    public static function for(string $moduleSlug): self
    {
        return new self($moduleSlug);
    }
}
