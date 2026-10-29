<?php

namespace App\View\Components\Ui;

use App\Livewire\Forms\RecurrencePicker as RecurrencePickerForm;
use App\Models\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The fields of a `RecurrencePicker` form object (US-12.1), embedded in the
 * form of the component that owns it: the preset as a segmented control,
 * then only what the preset needs — date and time for `once`; time,
 * weekdays, day of the month or "last day", or a cron expression for the
 * repeating ones, with start and end — and the next runs, shown in the
 * owner's timezone, which is named under the fields.
 *
 * Props:
 * - `form`: the component's `RecurrencePicker` property.
 *
 * Slots: none.
 */
class RecurrencePicker extends Component
{
    public function __construct(public RecurrencePickerForm $form) {}

    /**
     * The `wire:model` path of a field of the form.
     */
    public function model(string $field): string
    {
        return "{$this->form->getPropertyName()}.{$field}";
    }

    public function isOnce(): bool
    {
        return $this->form->frequency === RecurrenceFrequency::ONCE;
    }

    public function is(string $frequency): bool
    {
        return $this->form->frequency === $frequency;
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function preview(): array
    {
        return $this->form->preview();
    }

    public function render(): View
    {
        return view('components.ui.recurrence-picker');
    }
}
