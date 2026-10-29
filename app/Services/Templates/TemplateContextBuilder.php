<?php

namespace App\Services\Templates;

use App\Models\User;
use App\Support\Localization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Builds template contexts in the recipient's language and timezone (US-4.1,
 * US-3.2): the renderer neither translates nor formats, so every date and
 * amount is written out here, before it reaches it.
 *
 * Every context offers the common variables (`current_month_name`,
 * `current_year`, `current_date`, `next_month_name`), read on the
 * recipient's calendar: 01:00 UTC on November 1st is still October in São
 * Paulo. A schema's own variables go through `format()` according to their
 * type, which is also how the preview turns the schema examples into sample
 * data.
 */
final readonly class TemplateContextBuilder
{
    public function __construct(
        public string $locale,
        public string $timezone,
    ) {}

    /**
     * The language and timezone the user picked.
     */
    public static function forUser(User $user): self
    {
        return new self(
            Localization::normalize($user->preferredLocale()) ?? Localization::DEFAULT_LOCALE,
            $user->timezone ?: 'UTC',
        );
    }

    /**
     * The variables every context can offer, for schemas to include.
     *
     * @return list<TemplateVariable>
     */
    public static function commonVariables(): array
    {
        return [
            TemplateVariable::text('current_month_name', 'The name of the current month', 'October'),
            TemplateVariable::number('current_year', 'The current year', 2026),
            TemplateVariable::text('current_date', 'Today, written out', 'October 1, 2026'),
            TemplateVariable::text('next_month_name', 'The name of the next month', 'November'),
        ];
    }

    /**
     * The common variables at `$at` (now by default), on the recipient's
     * calendar and in their language.
     *
     * @return array{current_month_name: string, current_year: int, current_date: string, next_month_name: string}
     */
    public function common(?CarbonInterface $at = null): array
    {
        $today = $this->local($at ?? CarbonImmutable::now());

        return [
            'current_month_name' => $today->monthName,
            'current_year' => $today->year,
            'current_date' => $today->isoFormat('LL'),
            'next_month_name' => $today->startOfMonth()->addMonthNoOverflow()->monthName,
        ];
    }

    /**
     * The sample data a schema previews with: each example written out by
     * its type, under the common variables of `$at`.
     *
     * @return array<string, mixed>
     */
    public function sample(TemplateSchema $schema, ?CarbonInterface $at = null): array
    {
        $context = [];

        foreach ($schema->all() as $path => $variable) {
            data_set($context, $path, $this->format($variable, $variable->example));
        }

        return [...$context, ...$this->common($at)];
    }

    /**
     * A raw value written out the way its variable's type is shown: dates
     * spelled in the recipient's language (instants in their timezone),
     * amounts with two decimals, and each item of a list field by field.
     */
    public function format(TemplateVariable $variable, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($variable->type) {
            TemplateVariableType::Date => $this->formatDate($value),
            TemplateVariableType::DateTime => $this->formatDateTime($value),
            TemplateVariableType::Money => $this->formatMoney($value),
            TemplateVariableType::Collection => $this->formatItems($variable, $value),
            default => $value,
        };
    }

    /**
     * A calendar day, never shifted: "October 1, 2026", "1 de outubro de 2026".
     */
    public function formatDate(CarbonInterface|string $day): string
    {
        $date = is_string($day) ? CarbonImmutable::parse($day, 'UTC') : $day->toImmutable();

        return $date->settings(['locale' => $this->locale])->isoFormat('LL');
    }

    /**
     * An instant in the recipient's timezone: "October 1, 2026 11:30 AM",
     * "1 de outubro de 2026 às 11:30".
     */
    public function formatDateTime(CarbonInterface|string $instant): string
    {
        $date = is_string($instant) ? CarbonImmutable::parse($instant, 'UTC') : $instant->toImmutable();

        return $this->local($date)->isoFormat('LLL');
    }

    /**
     * An amount in major units as invoices print it (`x-ui.money`): two
     * decimals, a comma between thousands and a dot before the cents.
     */
    public function formatMoney(string|int|float $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formatItems(TemplateVariable $collection, mixed $items): array
    {
        if (! is_iterable($items)) {
            return [];
        }

        $formatted = [];

        foreach ($items as $item) {
            $row = [];

            foreach ($collection->fields as $name => $field) {
                $row[$name] = $this->format($field, data_get($item, $name));
            }

            $formatted[] = $row;
        }

        return $formatted;
    }

    private function local(CarbonInterface $instant): CarbonImmutable
    {
        return $instant->toImmutable()->setTimezone($this->timezone)->settings(['locale' => $this->locale]);
    }
}
