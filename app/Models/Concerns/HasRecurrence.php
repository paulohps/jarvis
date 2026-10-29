<?php

namespace App\Models\Concerns;

use App\Models\Recurrence;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * For the owners of a recurrence (`recurrences.schedulable`): at most one
 * per owner, enforced by the `(schedulable_type, schedulable_id)` unique.
 *
 * @property-read Recurrence|null $recurrence
 */
trait HasRecurrence
{
    /**
     * @return MorphOne<Recurrence, $this>
     */
    public function recurrence(): MorphOne
    {
        return $this->morphOne(Recurrence::class, 'schedulable');
    }
}
