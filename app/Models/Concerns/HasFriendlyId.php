<?php

namespace App\Models\Concerns;

use App\Support\FriendlyId;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Public identifier convention (database-schema Notes: friendly_id).
 *
 *  - Internal PK stays `bigint id`; relationships and foreign keys keep using it.
 *  - `friendly_id` (`{prefix}_{24 chars}`, unique, created by the
 *    `$table->friendlyId()` migration macro) is the route key. The numeric id
 *    never reaches a URL, Livewire argument or API payload.
 *  - Models using this trait declare their immutable prefix in `idPrefix()`.
 *
 * @property string $friendly_id
 */
trait HasFriendlyId
{
    /**
     * The prefix of this model's friendly ids (e.g. `inv`), unique per model
     * and at most five characters long.
     */
    abstract public static function idPrefix(): string;

    public static function bootHasFriendlyId(): void
    {
        static::creating(static function (Model $model): void {
            if (blank($model->getAttribute('friendly_id'))) {
                $model->setAttribute('friendly_id', FriendlyId::generate(static::idPrefix()));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'friendly_id';
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function byFriendlyId(Builder $query, string $friendlyId): void
    {
        $query->where($this->qualifyColumn('friendly_id'), $friendlyId);
    }
}
