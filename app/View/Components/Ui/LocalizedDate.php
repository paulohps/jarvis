<?php

namespace App\View\Components\Ui;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use InvalidArgumentException;

/**
 * A date or instant stored in UTC, shown in the reader's timezone and
 * language (US-3.2). Named `localized-date` because TallStackUI already
 * registers `<x-date>` (its date picker).
 *
 * Props:
 * - `value`: a Carbon instance or a date string read as UTC; null shows "—".
 * - `format`: `date` (10/01/2026, 01/10/2026 in pt_BR), `time` (08:00),
 *   `datetime` (both), or `calendar` for date-only values, which are never
 *   shifted to another timezone.
 * - `timezone`: overrides the signed-in user's timezone (UTC for guests).
 *
 * Slots: none.
 */
class LocalizedDate extends Component
{
    public const array FORMATS = ['date', 'time', 'datetime', 'calendar'];

    public ?CarbonInterface $instant;

    /**
     * @var 'date'|'time'|'datetime'|'calendar'
     */
    public string $format;

    public function __construct(
        CarbonInterface|string|null $value,
        string $format = 'datetime',
        public ?string $timezone = null,
    ) {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("The date format [{$format}] is not one of: ".implode(', ', self::FORMATS).'.');
        }

        $this->format = $format;

        $this->instant = is_string($value) ? CarbonImmutable::parse($value, 'UTC') : $value;
    }

    /**
     * The timezone the value is shown in.
     */
    public function displayTimezone(): string
    {
        if ($this->timezone !== null) {
            return $this->timezone;
        }

        $user = Auth::user();

        return $user instanceof User && filled($user->timezone) ? $user->timezone : 'UTC';
    }

    /**
     * The value in the reader's timezone and language, without changing the
     * original instance.
     */
    public function formatted(): string
    {
        if ($this->instant === null) {
            return '';
        }

        $date = $this->format === 'calendar'
            ? $this->instant->toImmutable()
            : $this->instant->toImmutable()->setTimezone($this->displayTimezone());

        $date = $date->settings(['locale' => app()->getLocale()]);

        return match ($this->format) {
            'date', 'calendar' => $date->isoFormat('L'),
            'time' => $date->isoFormat('HH:mm'),
            'datetime' => $date->isoFormat('L HH:mm'),
        };
    }

    /**
     * The `datetime` attribute of the `<time>` element: the calendar day, or
     * the instant with its offset in the displayed timezone.
     */
    public function machineReadable(): string
    {
        if ($this->instant === null) {
            return '';
        }

        return $this->format === 'calendar'
            ? $this->instant->format('Y-m-d')
            : $this->instant->toImmutable()->setTimezone($this->displayTimezone())->toIso8601String();
    }

    public function render(): View
    {
        return view('components.ui.localized-date');
    }
}
