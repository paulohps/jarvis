<?php

namespace App\Models;

use App\Exceptions\UnknownLookupSlug;
use App\Support\LookupCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Base of the lookup tables that replace every enum (database-schema: Lookup
 * tables). Each categorical value is a row referenced by its `slug`; each
 * model declares its slugs as constants (e.g. `InvoiceStatus::SENT`).
 *
 * `idFor()` and `bySlug()` read the whole table once per request or queued
 * job (see LookupCache) and refuse a slug that is not there. Saving or
 * deleting a row forgets that table, so the same request sees the change. A
 * subclass that declares `booted()` must call `parent::booted()`.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'name'])]
abstract class Lookup extends Model
{
    /**
     * The id of the row with the given slug.
     *
     * @throws UnknownLookupSlug
     */
    public static function idFor(string $slug): int
    {
        return static::bySlug($slug)->id;
    }

    /**
     * The row with the given slug. The instance is shared for the rest of the
     * request: read it, do not change it.
     *
     * @throws UnknownLookupSlug
     */
    public static function bySlug(string $slug): static
    {
        $row = app(LookupCache::class)->rowsOf(static::class)->get($slug);

        if (! $row instanceof static) {
            throw UnknownLookupSlug::in(static::class, $slug);
        }

        return $row;
    }

    protected static function booted(): void
    {
        $forgetTable = static function (): void {
            app(LookupCache::class)->forget(static::class);
        };

        static::saved($forgetTable);
        static::deleted($forgetTable);
    }

    /**
     * Rows still offered for new records. Only for the lookups with an
     * `is_active` column.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeSlug(Builder $query, string $slug): void
    {
        $query->where($this->qualifyColumn('slug'), $slug);
    }
}
