<?php

namespace App\Actions\Recurrences;

use App\Models\Contracts\Schedulable;
use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\Weekday;
use App\Rules\ValidCronExpression;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;

/**
 * Creates or replaces the recurrence of an owner — invoice recurrence,
 * WhatsApp message or reminder (US-12.1) — and schedules its next run.
 *
 * The preset decides which fields count: `once` runs at `starts_at` (which
 * must be in the future); `daily` at `time_of_day`; `weekly` at
 * `time_of_day` on one or more `weekdays` (slugs); `monthly` at
 * `time_of_day` on `day_of_month` (1-31, clamped to shorter months) or on
 * the last day; `custom_cron` on a valid `cron_expression`. The fields of
 * the other presets are cleared.
 *
 * Dates without an offset (`2026-10-10T09:00`, as a form sends them) are
 * read in the owner's timezone, which is stored with the rule; the instants
 * are kept in UTC. Without `starts_at`, a repeating rule starts now.
 */
final readonly class SaveRecurrence
{
    public function __construct(private RecurrenceCalculator $calculator) {}

    /**
     * @param  Model&Schedulable  $owner
     * @param  array{frequency?: mixed, time_of_day?: mixed, weekdays?: mixed, day_of_month?: mixed, is_last_day_of_month?: mixed, cron_expression?: mixed, starts_at?: mixed, ends_at?: mixed}  $data  `frequency` and `weekdays` are slugs
     *
     * @throws ValidationException
     */
    public function __invoke(Model $owner, array $data): Recurrence
    {
        $timezone = $owner->ownerTimezone();

        if (is_string($data['cron_expression'] ?? null)) {
            $data['cron_expression'] = trim($data['cron_expression']);
        }

        $frequency = is_string($data['frequency'] ?? null) ? $data['frequency'] : null;
        $isMonthlyOnLastDay = $frequency === RecurrenceFrequency::MONTHLY && filter_var($data['is_last_day_of_month'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $usesTimeOfDay = in_array($frequency, [RecurrenceFrequency::DAILY, RecurrenceFrequency::WEEKLY, RecurrenceFrequency::MONTHLY], true);

        /** @var array{frequency: string, time_of_day?: string|null, weekdays?: list<string>|null, day_of_month?: int|string|null, is_last_day_of_month?: bool|int|string|null, cron_expression?: string|null, starts_at?: string|null, ends_at?: string|null} $validated */
        $validated = Validator::make([...$data, 'timezone' => $timezone], [
            'timezone' => ['required', 'string', 'timezone:all'],
            'frequency' => ['required', 'string', Rule::in([
                RecurrenceFrequency::ONCE, RecurrenceFrequency::DAILY, RecurrenceFrequency::WEEKLY,
                RecurrenceFrequency::MONTHLY, RecurrenceFrequency::CUSTOM_CRON,
            ])],
            'time_of_day' => [$usesTimeOfDay ? 'required' : 'nullable', 'date_format:H:i'],
            'weekdays' => $frequency === RecurrenceFrequency::WEEKLY ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'weekdays.*' => ['string', 'distinct', Rule::in(array_keys(RecurrenceCalculator::ISO_WEEKDAYS))],
            'day_of_month' => [
                $frequency === RecurrenceFrequency::MONTHLY && ! $isMonthlyOnLastDay ? 'required' : 'nullable',
                'integer', 'between:1,31',
            ],
            'is_last_day_of_month' => ['nullable', 'boolean'],
            'cron_expression' => [$frequency === RecurrenceFrequency::CUSTOM_CRON ? 'required' : 'nullable', 'string', 'max:100', new ValidCronExpression],
            'starts_at' => [$frequency === RecurrenceFrequency::ONCE ? 'required' : 'nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ], attributes: [
            'time_of_day' => __('time'),
            'day_of_month' => __('day of the month'),
            'cron_expression' => __('cron expression'),
            'starts_at' => __('start'),
            'ends_at' => __('end'),
        ])->after(function (ValidationValidator $validator) use ($data, $frequency, $timezone): void {
            if ($validator->errors()->hasAny(['timezone', 'starts_at', 'ends_at'])) {
                return;
            }

            $startsAt = $this->instant($data['starts_at'] ?? null, $timezone);
            $endsAt = $this->instant($data['ends_at'] ?? null, $timezone);

            if ($frequency === RecurrenceFrequency::ONCE && $startsAt !== null && $startsAt->lessThanOrEqualTo(now())) {
                $validator->errors()->add('starts_at', __('The date and time must be in the future.'));
            }

            if ($endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt ?? now())) {
                $validator->errors()->add('ends_at', __('The end must be after the start.'));
            }
        })->validate();

        $frequency = $validated['frequency'];
        $isMonthly = $frequency === RecurrenceFrequency::MONTHLY;
        $isWeekly = $frequency === RecurrenceFrequency::WEEKLY;

        return DB::transaction(function () use ($owner, $validated, $frequency, $timezone, $isMonthly, $isWeekly, $isMonthlyOnLastDay, $usesTimeOfDay): Recurrence {
            $recurrence = Recurrence::query()
                ->where('schedulable_type', $owner->getMorphClass())
                ->where('schedulable_id', $owner->getKey())
                ->lockForUpdate()
                ->first() ?? (new Recurrence)->schedulable()->associate($owner);

            $recurrence->fill([
                'recurrence_frequency_id' => RecurrenceFrequency::idFor($frequency),
                'time_of_day' => $usesTimeOfDay ? ($validated['time_of_day'] ?? null) : null,
                'day_of_month' => $isMonthly && ! $isMonthlyOnLastDay ? (int) ($validated['day_of_month'] ?? null) : null,
                'is_last_day_of_month' => $isMonthlyOnLastDay,
                'cron_expression' => $frequency === RecurrenceFrequency::CUSTOM_CRON ? ($validated['cron_expression'] ?? null) : null,
                'timezone' => $timezone,
                'starts_at' => $this->instant($validated['starts_at'] ?? null, $timezone) ?? now()->toImmutable()->startOfMinute(),
                'ends_at' => $this->instant($validated['ends_at'] ?? null, $timezone),
            ]);
            $recurrence->save();

            $recurrence->weekdays()->sync($isWeekly
                ? array_map(fn (string $slug): int => Weekday::idFor($slug), $validated['weekdays'] ?? [])
                : []);

            $recurrence->unsetRelation('frequency')->unsetRelation('weekdays');
            $recurrence->fill(['next_run_at' => $this->calculator->nextRunAfter($recurrence, now()->toImmutable())])->save();

            return $recurrence;
        });
    }

    /**
     * A form or API date as a UTC instant: without an offset it is read in
     * the owner's timezone.
     */
    private function instant(mixed $value, string $timezone): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, $timezone)->utc();
    }
}
