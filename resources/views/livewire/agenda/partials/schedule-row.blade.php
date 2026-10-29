{{--
    One item of the schedule view: the round checkbox of a reminder's
    occurrence, or the source's color, then the time and the title — and its
    workspace, in the color the reader sees it in, when the agenda tells the
    workspaces apart. A reminder or event opens the form, another source's
    item its page. Expects $entry and $timezone.
--}}
@php
    $color = match ($entry->source) {
        'events' => 'bg-calendar-event',
        'invoices' => 'bg-calendar-invoice',
        'whatsapp' => 'bg-calendar-whatsapp',
        'shopping' => 'bg-calendar-shopping',
        'google' => 'bg-calendar-google',
        default => 'bg-calendar-reminder',
    };
    $workspaceHex = $this->workspaceHex($entry->workspace);
    $time = $entry->isAllDay
        ? __('All day')
        : $entry->startsAt->setTimezone($timezone)->format('H:i').($entry->endsAt !== null ? ' – '.$entry->endsAt->setTimezone($timezone)->format('H:i') : '');
@endphp

<x-ui.list-row wire:key="agenda-schedule-{{ $entry->key }}" data-agenda-entry="{{ $entry->key }}" data-agenda-source="{{ $entry->source }}">
    <x-slot:leading>
        @if ($entry->isCompletable())
            <button type="button"
                    role="checkbox"
                    aria-checked="{{ $entry->isCompleted() ? 'true' : 'false' }}"
                    aria-label="{{ $entry->isCompleted() ? __('Reopen :title', ['title' => $entry->title]) : __('Complete :title', ['title' => $entry->title]) }}"
                    wire:click="toggle('{{ $entry->occurrence->friendly_id }}')"
                    wire:loading.attr="disabled"
                    data-reminder-toggle="{{ $entry->occurrence->friendly_id }}"
                    class="grid size-11 place-items-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                <span aria-hidden="true" @class([
                    'grid size-6 place-items-center rounded-full border-2 transition',
                    'border-tint-fill bg-tint-fill text-on-tint' => $entry->isCompleted(),
                    'border-danger' => $entry->isOverdue(),
                    'border-separator' => ! $entry->isCompleted() && ! $entry->isOverdue(),
                ])>
                    @if ($entry->isCompleted())
                        <x-icon name="check" class="size-4" />
                    @endif
                </span>
            </button>
        @else
            <span aria-hidden="true" class="grid size-11 place-items-center">
                <span @class(['size-3 rounded-full', $color, 'opacity-50' => $entry->isProjected])></span>
            </span>
        @endif
    </x-slot:leading>

    <div class="flex min-w-0 items-baseline gap-3">
        <span @class(['w-24 shrink-0 text-[0.8125rem] tabular-nums', 'text-danger' => $entry->isOverdue(), 'text-secondary-label' => ! $entry->isOverdue()])>{{ $time }}</span>

        @if ($entry->reminder !== null)
            <button type="button" wire:click="edit('{{ $entry->reminder->friendly_id }}')" class="block min-w-0 flex-1 text-left focus:outline-none focus-visible:underline">
        @elseif ($entry->url !== null)
            <a href="{{ $entry->url }}" wire:navigate class="block min-w-0 flex-1 focus:outline-none focus-visible:underline">
        @else
            <span class="block min-w-0 flex-1">
        @endif
            <span @class(['block truncate text-[0.9375rem]', 'line-through text-secondary-label' => $entry->isCompleted(), 'text-secondary-label' => $entry->isProjected])>{{ $entry->title }}</span>
            @if (filled($entry->subtitle) || $workspaceHex !== null)
                <span class="flex min-w-0 items-center gap-2 text-[0.8125rem] text-secondary-label">
                    @if ($workspaceHex !== null)
                        <span class="inline-flex min-w-0 shrink-0 items-center gap-1" data-agenda-workspace="{{ $entry->workspace->friendly_id }}">
                            <span aria-hidden="true" class="size-2 rounded-sm" style="background-color: {{ $workspaceHex }}"></span>
                            {{ $entry->workspace->name }}
                        </span>
                    @endif
                    @if (filled($entry->subtitle))
                        <span class="truncate">{{ $entry->subtitle }}</span>
                    @endif
                </span>
            @endif
        @if ($entry->reminder !== null)
            </button>
        @elseif ($entry->url !== null)
            </a>
        @else
            </span>
        @endif
    </div>
</x-ui.list-row>
