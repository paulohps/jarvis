<?php

namespace App\Support;

use App\Models\Lookup;
use Illuminate\Database\Eloquent\Collection;

/**
 * Memo of the lookup tables read through `Lookup::idFor()` and
 * `Lookup::bySlug()`: each table is read once, whole, keyed by slug. Bound as
 * scoped, so it is flushed between requests and queued jobs: a row changed
 * by another process reaches the next one without a deploy.
 */
final class LookupCache
{
    /**
     * @var array<class-string<Lookup>, Collection<array-key, Lookup>>
     */
    private array $rowsBySlug = [];

    /**
     * The rows of a lookup keyed by slug, read on the first call.
     *
     * @param  class-string<Lookup>  $lookupClass
     * @return Collection<array-key, Lookup>
     */
    public function rowsOf(string $lookupClass): Collection
    {
        return $this->rowsBySlug[$lookupClass] ??= $lookupClass::query()->get()->keyBy('slug');
    }

    /**
     * Drop the memo of one lookup, so the next read queries it again.
     *
     * @param  class-string<Lookup>  $lookupClass
     */
    public function forget(string $lookupClass): void
    {
        unset($this->rowsBySlug[$lookupClass]);
    }

    /**
     * Drop the memo of every lookup, e.g. after seeders wrote the tables
     * without model events.
     */
    public function flush(): void
    {
        $this->rowsBySlug = [];
    }
}
