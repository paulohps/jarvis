<?php

namespace App\Models\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A wall-clock time without date or timezone (`time` column), read as
 * `HH:MM`. It is local to the record's own timezone, so it is never turned
 * into a Carbon instant, which would pin it to a date and to UTC.
 *
 * @implements CastsAttributes<string|null, string|DateTimeInterface|null>
 */
class TimeOfDay implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 5);
    }

    /**
     * Accepts `HH:MM`, `HH:MM:SS` or a date-time, whose time is kept.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?\z/', $value) === 1) {
            return strlen($value) === 5 ? "{$value}:00" : $value;
        }

        throw new InvalidArgumentException("The {$key} attribute expects a time as HH:MM or HH:MM:SS.");
    }
}
