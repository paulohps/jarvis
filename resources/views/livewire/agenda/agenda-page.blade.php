{{--
    The agenda of the reader's workspaces, like Google Calendar: the month
    grid, the week or the day on a 24-hour timeline, the schedule of the
    coming days, or the tasks by filter. The calendar views show every source
    the reader may see — reminders, events, invoices, WhatsApp messages —
    each with a chip that hides it; a reader in two or more workspaces also
    gets a chip per workspace, in the color they see it in, and each item of
    shared data marked with it. A click on an empty day or hour opens the
    form there. The form opens in a sheet.
--}}
@php
    $timezone = auth()->user()->timezone;
    $isCalendar = $view !== 'tasks';
    $minuteHeight = 1;
    $sourceColors = [
        'reminders' => 'bg-calendar-reminder',
        'events' => 'bg-calendar-event',
        'invoices' => 'bg-calendar-invoice',
        'whatsapp' => 'bg-calendar-whatsapp',
        'shopping' => 'bg-calendar-shopping',
        'google' => 'bg-calendar-google',
    ];
@endphp

<div class="mx-auto flex w-full max-w-6xl flex-col gap-4">
    <div class="flex flex-wrap justify-end gap-2">
        <x-button :text="__('Google Calendar')" icon="calendar" flat color="secondary" wire:click="openGoogle" data-agenda-google />
        <x-button :text="__('New')" icon="plus" wire:click="create" data-reminder-create />
    </div>

    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        @if ($isCalendar)
            <div class="flex min-w-0 items-center gap-2">
                <x-button :text="__('Today')" flat color="secondary" wire:click="today" data-agenda-today />
                <button type="button" wire:click="previous" aria-label="{{ __('Previous') }}" data-agenda-previous
                        class="grid size-9 place-items-center rounded-full text-label hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                    <x-icon name="chevron-left" class="size-5" />
                </button>
                <button type="button" wire:click="next" aria-label="{{ __('Next') }}" data-agenda-next
                        class="grid size-9 place-items-center rounded-full text-label hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                    <x-icon name="chevron-right" class="size-5" />
                </button>
                <h3 class="truncate text-[1.0625rem] font-semibold text-label" data-agenda-period>{{ $this->periodLabel() }}</h3>
            </div>
        @else
            <div></div>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <div class="hidden md:block">
                <x-ui.segmented-control name="view"
                                        wire:model.live="view"
                                        :label="__('View')"
                                        :value="$view"
                                        :options="$this->viewOptions()"
                                        data-reminder-view />
            </div>

            <div class="w-40 md:hidden">
                <x-select.native wire:model.live="view" :aria-label="__('View')" data-agenda-view-select>
                    @foreach ($this->viewOptions() as $option => $optionLabel)
                        <option value="{{ $option }}">{{ $optionLabel }}</option>
                    @endforeach
                </x-select.native>
            </div>

            <x-toggle wire:model.live="mine" :label="__('Only mine')" data-reminder-mine />
        </div>
    </div>

    @if ($isCalendar && count($this->sources) > 1)
        <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Calendars') }}">
            @foreach ($this->sources as $source)
                @php $isShown = ! in_array($source, $hide, true); @endphp
                <button type="button"
                        wire:click="toggleSource('{{ $source }}')"
                        aria-pressed="{{ $isShown ? 'true' : 'false' }}"
                        wire:key="agenda-source-{{ $source }}"
                        data-agenda-source-toggle="{{ $source }}"
                        @class([
                            'inline-flex min-h-8 items-center gap-1.5 rounded-full border px-3 text-[0.8125rem] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                            'border-separator bg-secondary-grouped-background text-label' => $isShown,
                            'border-dashed border-separator text-secondary-label line-through' => ! $isShown,
                        ])>
                    <span aria-hidden="true" @class(['size-2.5 rounded-full', $sourceColors[$source], 'opacity-30' => ! $isShown])></span>
                    {{ $this->sourceLabels()[$source] }}
                </button>
            @endforeach
        </div>
    @endif

    @if ($isCalendar && $this->showsWorkspaces())
        <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Workspaces') }}">
            @foreach ($this->agendaWorkspaces as $agendaWorkspace)
                @php $isShown = ! in_array($agendaWorkspace->friendly_id, $hideWorkspaces, true); @endphp
                <button type="button"
                        wire:click="toggleWorkspace('{{ $agendaWorkspace->friendly_id }}')"
                        aria-pressed="{{ $isShown ? 'true' : 'false' }}"
                        wire:key="agenda-workspace-{{ $agendaWorkspace->friendly_id }}"
                        data-agenda-workspace-toggle="{{ $agendaWorkspace->friendly_id }}"
                        @class([
                            'inline-flex min-h-8 items-center gap-1.5 rounded-full border px-3 text-[0.8125rem] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                            'border-separator bg-secondary-grouped-background text-label' => $isShown,
                            'border-dashed border-separator text-secondary-label line-through' => ! $isShown,
                        ])>
                    <span aria-hidden="true" @class(['size-2.5 rounded-sm', 'opacity-30' => ! $isShown]) style="background-color: {{ $this->workspaceHex($agendaWorkspace) }}"></span>
                    {{ $agendaWorkspace->name }}
                </button>
            @endforeach
        </div>
    @endif

    @if ($view === 'tasks')
        <x-ui.segmented-control name="filter"
                                wire:model.live="filter"
                                :label="__('Show')"
                                :value="$filter"
                                :options="$this->filterOptions()"
                                data-reminder-filter />
    @endif

    @if (filled($status))
        <p role="status" data-reminder-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @error('google')
        <p role="alert" data-agenda-google-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    @foreach ($this->googleAccounts->filter->hasFailed() as $failedAccount)
        <p role="alert" wire:key="agenda-google-failed-{{ $failedAccount->friendly_id }}" data-agenda-google-failed="{{ $failedAccount->friendly_id }}"
           class="flex flex-wrap items-center justify-between gap-2 rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">
            <span>{{ __('The calendars of :email are off the agenda: :error', ['email' => $failedAccount->email, 'error' => $failedAccount->last_error]) }}</span>
            <a href="{{ route('reminders.google.redirect') }}" class="font-semibold underline">{{ __('Connect again') }}</a>
        </p>
    @endforeach

    @error('occurrence')
        <p role="alert" data-reminder-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    @if ($view === 'month')
        @php $month = $this->anchor->month; @endphp
        <div class="overflow-hidden rounded-card bg-secondary-grouped-background shadow-soft" data-agenda-month>
            <div class="grid grid-cols-7 border-b border-separator">
                @foreach (array_slice(array_keys($this->days), 0, 7) as $day)
                    <div class="py-2 text-center text-[0.6875rem] font-medium text-secondary-label uppercase">
                        {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $day)->settings(['locale' => app()->getLocale()])->isoFormat('ddd') }}
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-7">
                @foreach ($this->days as $day => $entries)
                    @php $date = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $day); @endphp
                    <div wire:key="agenda-month-{{ $day }}"
                         data-agenda-day="{{ $day }}"
                         @class([
                             'relative min-h-20 border-separator p-1 sm:min-h-28',
                             'border-r' => $loop->iteration % 7 !== 0,
                             'border-b' => $loop->remaining >= 7,
                         ])>
                        <button type="button" wire:click="createAt('{{ $day }}')"
                                class="absolute inset-0 hover:bg-fill/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-tint"
                                aria-label="{{ __('New on :date', ['date' => $date->settings(['locale' => app()->getLocale()])->isoFormat('LL')]) }}"
                                data-agenda-create-at="{{ $day }}"></button>

                        <div class="pointer-events-none relative flex flex-col items-center gap-0.5">
                            <button type="button" wire:click="openDay('{{ $day }}')"
                                    data-agenda-open-day="{{ $day }}"
                                    @class([
                                        'pointer-events-auto grid size-6 place-items-center rounded-full text-[0.75rem] tabular-nums focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                                        'bg-tint-fill font-semibold text-on-tint' => $this->isToday($day),
                                        'text-label hover:bg-fill' => ! $this->isToday($day) && $date->month === $month,
                                        'text-secondary-label hover:bg-fill' => ! $this->isToday($day) && $date->month !== $month,
                                    ])>{{ $date->day }}</button>

                            <ul class="flex w-full flex-wrap gap-0.5 sm:flex-col sm:flex-nowrap">
                                @foreach (array_slice($entries, 0, \App\Livewire\Agenda\AgendaPage::MONTH_CELL_LIMIT) as $entry)
                                    <li class="min-w-0 sm:w-full" wire:key="agenda-month-entry-{{ $entry->key }}">
                                        @include('livewire.agenda.partials.entry-chip', ['entry' => $entry, 'timezone' => $timezone])
                                    </li>
                                @endforeach
                            </ul>

                            @if (count($entries) > \App\Livewire\Agenda\AgendaPage::MONTH_CELL_LIMIT)
                                <button type="button" wire:click="openDay('{{ $day }}')"
                                        class="pointer-events-auto w-full rounded-[0.25rem] px-1 text-left text-[0.6875rem] font-medium text-secondary-label hover:bg-fill"
                                        data-agenda-more="{{ $day }}">
                                    {{ __('+:count more', ['count' => count($entries) - \App\Livewire\Agenda\AgendaPage::MONTH_CELL_LIMIT]) }}
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($view === 'week' || $view === 'day')
        @php
            $dayKeys = array_keys($this->days);
            $columns = 'grid-template-columns: 3.25rem repeat('.count($dayKeys).', minmax(0, 1fr));';
            $now = now($timezone);
        @endphp
        <div class="overflow-hidden rounded-card bg-secondary-grouped-background shadow-soft" data-agenda-timeline="{{ $view }}">
            <div class="grid border-b border-separator" style="{{ $columns }}">
                <div></div>
                @foreach ($dayKeys as $day)
                    @php $date = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $day)->settings(['locale' => app()->getLocale()]); @endphp
                    <button type="button" wire:click="openDay('{{ $day }}')" wire:key="agenda-head-{{ $day }}"
                            class="flex flex-col items-center gap-0.5 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-tint">
                        <span @class(['text-[0.6875rem] font-medium uppercase', 'text-tint' => $this->isToday($day), 'text-secondary-label' => ! $this->isToday($day)])>{{ $date->isoFormat('ddd') }}</span>
                        <span @class([
                            'grid size-8 place-items-center rounded-full text-[1.0625rem] tabular-nums',
                            'bg-tint-fill font-semibold text-on-tint' => $this->isToday($day),
                            'text-label' => ! $this->isToday($day),
                        ])>{{ $date->day }}</span>
                    </button>
                @endforeach
            </div>

            <div class="grid border-b border-separator" style="{{ $columns }}" data-agenda-all-day>
                <div class="px-1 py-1.5 text-right text-[0.625rem] text-secondary-label">{{ __('All day') }}</div>
                @foreach ($dayKeys as $day)
                    <ul class="flex min-w-0 flex-col gap-0.5 border-l border-separator p-0.5" wire:key="agenda-all-day-{{ $day }}">
                        @foreach ($this->allDay($day) as $entry)
                            <li class="min-w-0" wire:key="agenda-all-day-entry-{{ $entry->key }}">
                                @include('livewire.agenda.partials.entry-chip', ['entry' => $entry, 'timezone' => $timezone])
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>

            <div class="max-h-[68vh] overflow-y-auto" x-data x-init="$el.scrollTop = {{ 7 * 60 * $minuteHeight }}">
                <div class="relative grid" style="{{ $columns }}">
                    <div class="relative" style="height: {{ 1440 * $minuteHeight }}px">
                        @for ($hour = 1; $hour < 24; $hour++)
                            <span class="absolute right-1.5 -translate-y-1/2 text-[0.625rem] text-secondary-label tabular-nums" style="top: {{ $hour * 60 * $minuteHeight }}px">{{ sprintf('%02d:00', $hour) }}</span>
                        @endfor
                    </div>

                    @foreach ($dayKeys as $day)
                        <div class="relative border-l border-separator" style="height: {{ 1440 * $minuteHeight }}px" wire:key="agenda-column-{{ $day }}" data-agenda-day="{{ $day }}">
                            @for ($hour = 0; $hour < 24; $hour++)
                                <button type="button" wire:click="createAt('{{ $day }}', {{ $hour }})"
                                        class="absolute inset-x-0 border-t border-separator/60 hover:bg-fill/50 focus:outline-none focus-visible:bg-fill"
                                        style="top: {{ $hour * 60 * $minuteHeight }}px; height: {{ 60 * $minuteHeight }}px"
                                        aria-label="{{ __('New at :time', ['time' => $day.' '.sprintf('%02d:00', $hour)]) }}"
                                        data-agenda-create-at="{{ $day }}T{{ sprintf('%02d', $hour) }}"></button>
                            @endfor

                            @foreach ($this->timeline($day) as $item)
                                @include('livewire.agenda.partials.timeline-entry', ['item' => $item, 'timezone' => $timezone, 'minuteHeight' => $minuteHeight])
                            @endforeach

                            @if ($this->isToday($day))
                                <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 z-30 h-0.5 bg-danger"
                                     style="top: {{ ($now->hour * 60 + $now->minute) * $minuteHeight }}px">
                                    <span class="absolute -top-1 -left-1 size-2.5 rounded-full bg-danger"></span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @elseif ($view === 'schedule')
        @php $scheduled = array_filter($this->days); @endphp
        @forelse ($scheduled as $day => $entries)
            <x-ui.inset-grouped-list :header="$this->dayLabel($day)" wire:key="agenda-schedule-{{ $day }}" data-agenda-schedule-day="{{ $day }}">
                @foreach ($entries as $entry)
                    @include('livewire.agenda.partials.schedule-row', ['entry' => $entry, 'timezone' => $timezone])
                @endforeach
            </x-ui.inset-grouped-list>
        @empty
            <x-ui.empty-state icon="calendar-days"
                              :title="__('Nothing on the agenda')"
                              :description="__('Add a reminder or an event, once or on a recurring schedule.')" />
        @endforelse
    @elseif ($this->occurrences->isEmpty())
        <x-ui.empty-state icon="check-circle"
                          :title="match ($filter) {
                              'overdue' => __('Nothing overdue'),
                              'completed' => __('Nothing completed yet'),
                              default => __('No pending reminders'),
                          }"
                          :description="$filter === 'pending' ? __('Add a reminder or task, with or without a date, once or on a recurring schedule.') : null" />
    @else
        <x-ui.inset-grouped-list>
            @foreach ($this->occurrences as $occurrence)
                @include('livewire.agenda.partials.occurrence-row', [
                    'reminder' => $occurrence->reminder,
                    'occurrence' => $occurrence,
                    'dueAt' => $occurrence->due_at,
                    'rowKey' => 'reminder-occurrence-'.$occurrence->friendly_id,
                    'dateFormat' => 'datetime',
                ])
            @endforeach
        </x-ui.inset-grouped-list>
    @endif

    <x-modal wire="showGoogle" :title="__('Google Calendar')">
        <div class="flex flex-col gap-5" data-agenda-google-sheet>
            <p class="text-sm text-secondary-label">{{ __('Your Google calendars show on your agenda only, read only. Their events open in Google.') }}</p>

            @foreach ($this->googleAccounts as $account)
                <x-ui.inset-grouped-list wire:key="agenda-google-account-{{ $account->friendly_id }}" data-agenda-google-account="{{ $account->friendly_id }}">
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate normal-case">{{ $account->email }}</span>
                            <span class="flex shrink-0 gap-1">
                                <x-button type="button" :text="__('Refresh')" icon="arrow-path" flat color="secondary" sm wire:click="syncGoogle('{{ $account->friendly_id }}')" data-agenda-google-sync="{{ $account->friendly_id }}" />
                                <x-ui.confirm-dialog :title="__('Disconnect :email?', ['email' => $account->email])"
                                                     :description="__('Its calendars leave your agenda, and Jarvis stops reading them.')"
                                                     :confirm-label="__('Disconnect')"
                                                     danger
                                                     wire:click="disconnectGoogle('{{ $account->friendly_id }}')">
                                    <x-slot:trigger>
                                        <x-button type="button" :text="__('Disconnect')" icon="trash" flat color="red" sm data-agenda-google-disconnect="{{ $account->friendly_id }}" />
                                    </x-slot:trigger>
                                </x-ui.confirm-dialog>
                            </span>
                        </div>
                    </x-slot:header>

                    @forelse ($account->calendars as $calendar)
                        <x-ui.list-row wire:key="agenda-google-calendar-{{ $calendar->friendly_id }}">
                            <x-slot:leading>
                                <span aria-hidden="true" class="block size-3 rounded-full bg-calendar-google" @if ($calendar->color) style="background-color: {{ $calendar->color }}" @endif></span>
                            </x-slot:leading>
                            <span class="block truncate text-[0.9375rem]">{{ $calendar->name }}</span>
                            <x-slot:trailing>
                                <button type="button"
                                        role="switch"
                                        aria-checked="{{ $calendar->is_visible ? 'true' : 'false' }}"
                                        aria-label="{{ __('Show :name', ['name' => $calendar->name]) }}"
                                        wire:click="toggleGoogleCalendar('{{ $calendar->friendly_id }}')"
                                        data-agenda-google-calendar="{{ $calendar->friendly_id }}"
                                        @class([
                                            'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                                            'bg-switch-on' => $calendar->is_visible,
                                            'bg-fill' => ! $calendar->is_visible,
                                        ])>
                                    <span aria-hidden="true" @class([
                                        'inline-block size-6 rounded-full bg-on-tint shadow-soft transition',
                                        'translate-x-5.5' => $calendar->is_visible,
                                        'translate-x-0.5' => ! $calendar->is_visible,
                                    ])></span>
                                </button>
                            </x-slot:trailing>
                        </x-ui.list-row>
                    @empty
                        <x-ui.list-row :title="__('No calendars yet. Refresh to read them.')" />
                    @endforelse
                </x-ui.inset-grouped-list>
            @endforeach

            <div class="flex justify-between gap-2">
                <x-button type="button" :text="__('Close')" flat color="secondary" x-on:click="show = false" />
                <x-button :href="route('reminders.google.redirect')" :text="__('Connect a Google account')" icon="plus" data-agenda-google-connect />
            </div>
        </div>
    </x-modal>

    <x-modal wire="showForm" :title="$editingId === null ? __('New') : ($kind === 'event' ? __('Edit event') : __('Edit reminder'))">
        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-reminder-form>
            <x-ui.segmented-control name="kind"
                                    wire:model.live="kind"
                                    :label="__('Type')"
                                    :value="$kind"
                                    :options="['reminder' => __('Reminder'), 'event' => __('Event')]"
                                    data-reminder-kind />

            @if ($this->reminderWorkspaces->count() > 1)
                <x-select.native :label="__('Workspace')" wire:model.live="workspace" data-reminder-workspace>
                    @foreach ($this->reminderWorkspaces as $formWorkspace)
                        <option value="{{ $formWorkspace->friendly_id }}" wire:key="workspace-option-{{ $formWorkspace->friendly_id }}">{{ $formWorkspace->name }}</option>
                    @endforeach
                </x-select.native>
            @else
                @error('workspace') <p role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
            @endif

            <x-input :label="__('Title')" wire:model="title" maxlength="255" required />

            <x-textarea :label="__('Note')" wire:model="note" rows="3" />

            <x-select.native :label="__('Responsible')" wire:model="assigned_to" data-reminder-assigned-to>
                <option value="">{{ __('Nobody') }}</option>
                @foreach ($this->assignees as $assignee)
                    <option value="{{ $assignee->friendly_id }}" wire:key="assignee-option-{{ $assignee->friendly_id }}">{{ $assignee->name }}</option>
                @endforeach
            </x-select.native>

            <x-toggle wire:model.live="repeats" :label="__('Repeat')" data-reminder-repeats />

            @if ($repeats || $kind === 'event' || $hasDate)
                <x-toggle wire:model.live="is_all_day" :label="__('All day')" data-reminder-all-day />
            @endif

            @if ($repeats)
                <x-ui.recurrence-picker :form="$recurrence" />
            @else
                @if ($kind !== 'event')
                    <x-toggle wire:model.live="hasDate" :label="__('Date and time')" data-reminder-has-date />
                @endif

                @if ($hasDate || $kind === 'event')
                    @if ($is_all_day)
                        <x-input type="date" :label="__('Date')" wire:model="due_date" data-reminder-due-date />
                        @error('due_at') <p class="-mt-3 text-sm text-danger">{{ $message }}</p> @enderror
                    @else
                        <x-input type="datetime-local" :label="__('Date and time')" wire:model="due_at" data-reminder-due-at />
                    @endif
                @endif
            @endif

            @if ($kind === 'event' && ! $is_all_day)
                <x-select.native :label="__('Duration')" wire:model="duration_minutes" data-reminder-duration>
                    @foreach ($this->durationOptions() as $minutes => $durationLabel)
                        <option value="{{ $minutes }}">{{ $durationLabel }}</option>
                    @endforeach
                </x-select.native>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    @if ($editingId !== null)
                        <x-ui.confirm-dialog :title="$kind === 'event' ? __('Delete this event?') : __('Delete this reminder?')"
                                             :description="__('It leaves every list and is not notified any more.')"
                                             :confirm-label="__('Delete')"
                                             danger
                                             wire:click="delete('{{ $editingId }}')">
                            <x-slot:trigger>
                                <x-button type="button" :text="__('Delete')" icon="trash" flat color="red" data-reminder-delete />
                            </x-slot:trigger>
                        </x-ui.confirm-dialog>
                    @endif
                </div>

                <div class="flex gap-2">
                    <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                    <x-button type="submit" :text="__('Save')" icon="check" loading="save" />
                </div>
            </div>
        </form>
    </x-modal>
</div>
