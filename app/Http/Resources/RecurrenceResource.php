<?php

namespace App\Http\Resources;

use App\Models\Recurrence;
use App\Models\Weekday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The schedule of a reminder or WhatsApp message (US-12.1), in the shape the
 * apps send it back: the preset (`frequency` slug) and its fields — the
 * wall-clock `time_of_day` and the weekday slugs are read in `timezone`,
 * the instants are in UTC — plus the next run and whether it is paused.
 *
 * @mixin Recurrence
 */
class RecurrenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Recurrence $recurrence */
        $recurrence = $this->resource;

        return [
            'frequency' => $recurrence->frequency->slug,
            'time_of_day' => $recurrence->time_of_day,
            'weekdays' => $recurrence->weekdays->sortBy('id')->map(fn (Weekday $weekday): string => $weekday->slug)->values()->all(),
            'day_of_month' => $recurrence->day_of_month,
            'is_last_day_of_month' => $recurrence->is_last_day_of_month,
            'cron_expression' => $recurrence->cron_expression,
            'timezone' => $recurrence->timezone,
            'starts_at' => $recurrence->starts_at->toIso8601String(),
            'ends_at' => $recurrence->ends_at?->toIso8601String(),
            'next_run_at' => $recurrence->next_run_at?->toIso8601String(),
            'is_paused' => $recurrence->is_paused,
        ];
    }
}
