{{--
    One item on the month grid or the all-day row: the source's color, the
    time (unless all day) and the title — with a stripe in its workspace's
    color when the agenda tells the workspaces apart. A reminder or event
    opens the form, another source's item its page. Expects $entry and
    $timezone.
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
    $classes = 'pointer-events-auto flex w-full min-w-0 items-center gap-1 rounded-[0.25rem] px-1 py-px text-left text-[0.6875rem] leading-4 text-label hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint'
        .($workspaceHex !== null ? ' border-l-[3px]' : '');
    $workspaceAttributes = $workspaceHex !== null
        ? 'style="border-left-color: '.e($workspaceHex).'" data-agenda-workspace="'.e($entry->workspace->friendly_id).'"'
        : '';
@endphp

@if ($entry->reminder !== null)
    <button type="button" wire:click="edit('{{ $entry->reminder->friendly_id }}')" class="{{ $classes }}"
            title="{{ $entry->title }}" data-agenda-entry="{{ $entry->key }}" data-agenda-source="{{ $entry->source }}" {!! $workspaceAttributes !!}>
@elseif ($entry->url !== null)
    <a href="{{ $entry->url }}" wire:navigate class="{{ $classes }}"
       title="{{ $entry->title }}" data-agenda-entry="{{ $entry->key }}" data-agenda-source="{{ $entry->source }}" {!! $workspaceAttributes !!}>
@else
    <span class="{{ $classes }}" title="{{ $entry->title }}" data-agenda-entry="{{ $entry->key }}" data-agenda-source="{{ $entry->source }}" {!! $workspaceAttributes !!}>
@endif
    <span aria-hidden="true" @class([
        'size-2 shrink-0 rounded-full',
        $color,
        'opacity-50' => $entry->isProjected || $entry->isCompleted(),
    ])></span>
    @unless ($entry->isAllDay)
        <span class="hidden shrink-0 text-secondary-label tabular-nums sm:inline">{{ $entry->startsAt->setTimezone($timezone)->format('H:i') }}</span>
    @endunless
    <span @class([
        'hidden truncate sm:inline',
        'line-through text-secondary-label' => $entry->isCompleted(),
        'text-danger' => $entry->isOverdue(),
    ])>{{ $entry->title }}</span>
    <span class="sr-only sm:hidden">{{ $entry->title }}</span>
@if ($entry->reminder !== null)
    </button>
@elseif ($entry->url !== null)
    </a>
@else
    </span>
@endif
