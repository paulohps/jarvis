<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Stands in for a `recurrences` row in the morph map tests: same
 * `schedulable` morph, on a table created by the test itself.
 *
 * @property string $schedulable_type
 * @property int $schedulable_id
 */
class ScheduleProbe extends Model
{
    protected $table = 'schedule_probes';

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }
}
