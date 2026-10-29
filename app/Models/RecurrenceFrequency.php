<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The repetition presets of a recurrence (Key Concept "Recurrence").
 */
class RecurrenceFrequency extends Lookup
{
    public const string ONCE = 'once';

    public const string DAILY = 'daily';

    public const string WEEKLY = 'weekly';

    public const string MONTHLY = 'monthly';

    public const string CUSTOM_CRON = 'custom_cron';

    /**
     * @return HasMany<Recurrence, $this>
     */
    public function recurrences(): HasMany
    {
        return $this->hasMany(Recurrence::class);
    }
}
