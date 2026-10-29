<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The days of the week. The `id` is the ISO-8601 number (1 = Monday …
 * 7 = Sunday), the same as `CarbonInterface::isoWeekday()`.
 */
class Weekday extends Lookup
{
    public const string MONDAY = 'monday';

    public const string TUESDAY = 'tuesday';

    public const string WEDNESDAY = 'wednesday';

    public const string THURSDAY = 'thursday';

    public const string FRIDAY = 'friday';

    public const string SATURDAY = 'saturday';

    public const string SUNDAY = 'sunday';

    /**
     * Weekly recurrences that run on this day.
     *
     * @return BelongsToMany<Recurrence, $this>
     */
    public function recurrences(): BelongsToMany
    {
        return $this->belongsToMany(Recurrence::class)->withTimestamps();
    }
}
