<?php

namespace Tests\Fixtures;

use App\Livewire\Forms\RecurrencePicker;
use App\Models\Reminder;
use Livewire\Component;

/**
 * Stands in for the module forms that embed the recurrence picker
 * (invoice recurrences, WhatsApp messages, reminders): a field of its own
 * next to the picker, saved together.
 */
class RecurrencePickerHost extends Component
{
    public Reminder $reminder;

    public string $title = '';

    public RecurrencePicker $recurrence;

    public function mount(Reminder $reminder): void
    {
        $this->reminder = $reminder;
        $this->title = $reminder->title;
        $this->recurrence->load($reminder->ownerTimezone(), $reminder->recurrence);
    }

    public function save(): void
    {
        $this->validate(['title' => ['required', 'string']]);

        $this->reminder->update(['title' => $this->title]);
        $this->recurrence->save($this->reminder);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <form wire:submit="save">
                <x-input wire:model="title" />
                <x-ui.recurrence-picker :form="$recurrence" />
                <button type="submit">Save</button>
            </form>
            BLADE;
    }
}
