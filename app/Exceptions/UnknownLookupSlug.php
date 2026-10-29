<?php

namespace App\Exceptions;

use App\Models\Lookup;
use InvalidArgumentException;

/**
 * Code asked a lookup for a slug its table does not have: either a typo in a
 * constant or a lookup that was not seeded. A bug, never a user error.
 */
final class UnknownLookupSlug extends InvalidArgumentException
{
    /**
     * @param  class-string<Lookup>  $lookupClass
     */
    public static function in(string $lookupClass, string $slug): self
    {
        return new self("The lookup [{$lookupClass}] has no row with the slug [{$slug}]; check the constant or seed the table.");
    }
}
