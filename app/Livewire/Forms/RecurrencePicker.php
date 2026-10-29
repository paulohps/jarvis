<?php

namespace App\Livewire\Forms;

use App\Actions\Recurrences\SaveRecurrence;
use App\Models\Contracts\Schedulable;
use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\Weekday;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Form;
use Throwable;

/**
 * The recurrence fields of any form (US-12.1): invoice recurrences
 * (US-6.3), WhatsApp messages (US-7.3) and reminders (US-9.1) declare a
 * `public RecurrencePicker $recurrence;` property, draw it with
 * `<x-ui.recurrence-picker :form="$recurrence" />` and save it with
 * `$this->recurrence->save($owner)`.
 *
 * Dates and times are wall-clock values in `timezone`, the owner's, as the
 * native inputs send them; `SaveRecurrence` validates them and stores UTC.
 * Its errors come back under the property name (`recurrence.weekdays`), so
 * they show next to the picker's own fields.
 */
class RecurrencePicker extends Form
{
    public const string INPUT_DATETIME_FORMAT = 'Y-m-d\TH:i';

    public const int PREVIEW_COUNT = 3;

    public string $frequency = RecurrenceFrequency::DAILY;

    public string $time_of_day = '09:00';

    /**
     * The weekday slugs of a weekly rule.
     *
     * @var list<string>
     */
    public array $weekdays = [];

    public int|string|null $day_of_month = 1;

    public bool $is_last_day_of_month = false;

    public string $cron_expression = '';

    /**
     * The single run of `once`, or the optional start of a repeating rule
     * (`2026-10-10T09:00`, local).
     */
    public string $starts_at = '';

    public string $ends_at = '';

    /**
     * The owner's timezone, shown next to the fields and used to read them.
     */
    public string $timezone = 'UTC';

    /**
     * Start empty in the owner's timezone, or with the owner's saved rule.
     */
    public function load(string $timezone, ?Recurrence $recurrence = null): void
    {
        $this->reset();
        $this->timezone = $recurrence->timezone ?? $timezone;

        if ($recurrence === null) {
            return;
        }

        $local = fn (?DateTimeInterface $instant): string => $instant === null
            ? ''
            : CarbonImmutable::instance($instant)->setTimezone($this->timezone)->format(self::INPUT_DATETIME_FORMAT);

        $this->frequency = $recurrence->frequency->slug;
        $this->time_of_day = $recurrence->time_of_day ?? $this->time_of_day;
        $this->weekdays = array_values($recurrence->weekdays->map(fn (Weekday $weekday): string => $weekday->slug)->all());
        $this->day_of_month = $recurrence->day_of_month ?? $this->day_of_month;
        $this->is_last_day_of_month = $recurrence->is_last_day_of_month;
        $this->cron_expression = $recurrence->cron_expression ?? '';
        $this->starts_at = $local($recurrence->starts_at);
        $this->ends_at = $local($recurrence->ends_at);
    }

    /**
     * Create or replace the owner's recurrence.
     *
     * @param  Model&Schedulable  $owner
     *
     * @throws ValidationException with the keys under this form's property
     */
    public function save(Model $owner): Recurrence
    {
        $this->getComponent()->resetErrorBag(array_map(
            fn (string $field): string => "{$this->getPropertyName()}.{$field}",
            array_keys($this->data()),
        ));

        try {
            return app(SaveRecurrence::class)($owner, $this->data());
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors["{$this->getPropertyName()}.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The input of `SaveRecurrence`, with only the current preset's fields.
     *
     * @return array{frequency: string, time_of_day: string|null, weekdays: list<string>, day_of_month: int|string|null, is_last_day_of_month: bool, cron_expression: string|null, starts_at: string|null, ends_at: string|null}
     */
    public function data(): array
    {
        $isOnce = $this->frequency === RecurrenceFrequency::ONCE;
        $isMonthly = $this->frequency === RecurrenceFrequency::MONTHLY;

        return [
            'frequency' => $this->frequency,
            'time_of_day' => $isOnce || $this->frequency === RecurrenceFrequency::CUSTOM_CRON ? null : $this->time_of_day,
            'weekdays' => $this->frequency === RecurrenceFrequency::WEEKLY ? $this->weekdays : [],
            'day_of_month' => $isMonthly && ! $this->is_last_day_of_month ? $this->day_of_month : null,
            'is_last_day_of_month' => $isMonthly && $this->is_last_day_of_month,
            'cron_expression' => $this->frequency === RecurrenceFrequency::CUSTOM_CRON ? $this->cron_expression : null,
            'starts_at' => filled($this->starts_at) ? $this->starts_at : null,
            'ends_at' => $isOnce || blank($this->ends_at) ? null : $this->ends_at,
        ];
    }

    /**
     * The next runs as currently filled in (UTC instants, shown in
     * `timezone`), or none while the fields are incomplete or invalid.
     *
     * @return list<CarbonImmutable>
     */
    public function preview(): array
    {
        try {
            $recurrence = $this->draft();

            if ($recurrence === null) {
                return [];
            }

            return app(RecurrenceCalculator::class)->nextRuns($recurrence, now()->toImmutable(), self::PREVIEW_COUNT);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The rule as currently filled in, unsaved, with its frequency and
     * weekdays loaded, for computing runs before saving (the recurrence
     * preview, the invoice preview). Null while a cron expression is not
     * valid.
     *
     * @throws Throwable when the fields cannot make a rule (unknown preset, unreadable date)
     */
    public function draft(): ?Recurrence
    {
        $data = $this->data();

        if ($data['frequency'] === RecurrenceFrequency::CUSTOM_CRON && ! CronExpression::isValidExpression((string) $data['cron_expression'])) {
            return null;
        }

        $recurrence = new Recurrence([
            'time_of_day' => $data['time_of_day'],
            'day_of_month' => $data['day_of_month'] === null || $data['day_of_month'] === '' ? null : (int) $data['day_of_month'],
            'is_last_day_of_month' => $data['is_last_day_of_month'],
            'cron_expression' => $data['cron_expression'],
            'timezone' => $this->timezone,
            'starts_at' => $this->instant($data['starts_at']) ?? now()->toImmutable()->startOfMinute(),
            'ends_at' => $this->instant($data['ends_at']),
        ]);
        $recurrence->setRelation('frequency', RecurrenceFrequency::bySlug($data['frequency']));
        $recurrence->setRelation('weekdays', Weekday::query()->whereIn('slug', $data['weekdays'])->get());

        return $recurrence;
    }

    /**
     * The presets, labeled in the reader's language, in display order.
     *
     * @return array<string, string>
     */
    public function frequencyOptions(): array
    {
        return [
            RecurrenceFrequency::ONCE => __('Once'),
            RecurrenceFrequency::DAILY => __('Daily'),
            RecurrenceFrequency::WEEKLY => __('Weekly'),
            RecurrenceFrequency::MONTHLY => __('Monthly'),
            RecurrenceFrequency::CUSTOM_CRON => __('Cron'),
        ];
    }

    /**
     * The weekdays from Monday, with their short labels.
     *
     * @return array<string, string>
     */
    public function weekdayOptions(): array
    {
        return [
            Weekday::MONDAY => __('Mon'),
            Weekday::TUESDAY => __('Tue'),
            Weekday::WEDNESDAY => __('Wed'),
            Weekday::THURSDAY => __('Thu'),
            Weekday::FRIDAY => __('Fri'),
            Weekday::SATURDAY => __('Sat'),
            Weekday::SUNDAY => __('Sun'),
        ];
    }

    private function instant(?string $local): ?CarbonImmutable
    {
        return blank($local) ? null : CarbonImmutable::parse($local, $this->timezone)->utc();
    }
}
