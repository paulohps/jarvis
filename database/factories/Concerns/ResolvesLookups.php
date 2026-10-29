<?php

namespace Database\Factories\Concerns;

use App\Exceptions\UnknownLookupSlug;
use App\Models\Lookup;
use Illuminate\Support\Str;

/**
 * Lets a factory point to a lookup row by slug whether or not the lookups
 * were seeded: tests run on an empty schema, so a missing row is created
 * with just enough columns (only that row, so a test may still insert the
 * others itself).
 */
trait ResolvesLookups
{
    /**
     * The id of the lookup row with the slug, created when missing.
     *
     * @param  class-string<Lookup>  $lookupClass
     * @param  array<string, mixed>  $attributesWhenMissing  defaults to a headline `name`
     */
    protected static function lookupId(string $lookupClass, string $slug, array $attributesWhenMissing = []): int
    {
        try {
            return $lookupClass::idFor($slug);
        } catch (UnknownLookupSlug) {
            $row = new $lookupClass;
            $row->forceFill([
                'slug' => $slug,
                ...($attributesWhenMissing ?: ['name' => Str::headline($slug)]),
            ])->save();

            return $row->id;
        }
    }
}
