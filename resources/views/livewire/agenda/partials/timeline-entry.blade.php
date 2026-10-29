{{--
    One timed item on the week or day grid, placed by DayTimeline: `top`
    and `height` in minutes of the day, side by side with the items it
    overlaps, with a dot in its workspace's color at the corner when the
    agenda tells the workspaces apart. Expects $item, $timezone and
    $minuteHeight (pixels).
--}}
@php
    $entry = $item['entry'];
    [$border, $fill] = match ($entry->source) {
        'events' => ['border-calendar-event', 'bg-calendar-event/15'],
        'invoices' => ['border-calendar-invoice', 'bg-calendar-invoice/15'],
        'whatsapp' => ['border-calendar-whatsapp', 'bg-calendar-whatsapp/15'],
        'shopping' => ['border-calendar-shopping', 'bg-calendar-shopping/15'],
        'google' => ['border-calendar-google', 'bg-calendar-google/15'],
        default => ['border-calendar-reminder', 'bg-calendar-reminder/15'],
    };
    $style = sprintf(
        'top: %.1fpx; height: %.1fpx; left: calc(%s%% + 1px); width: calc(%s%% - 2px);',
        $item['top'] * $minuteHeight,
        max($item['height'] * $minuteHeight - 1, 18),
        round($item['lane'] / $item['lanes'] * 100, 3),
        round(100 / $item['lanes'], 3),
    );
    $classes = "absolute z-10 flex flex-col overflow-hidden rounded-[0.375rem] border-l-[3px] {$border} {$fill} px-1.5 py-0.5 text-left text-[0.6875rem] leading-4 text-label hover:z-20 hover:shadow-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-tint";
    $workspaceHex = $this->workspaceHex($entry->workspace);
    $time = $entry->startsAt->setTimezone($timezone)->format('H:i').($entry->endsAt !== null ? ' – '.$entry->endsAt->setTimezone($timezone)->format('H:i') : '');
@endphp

@if ($entry->reminder !== null)
    <button type="button" wire:click="edit('{{ $entry->reminder->friendly_id }}')"
@elseif ($entry->url !== null)
    <a href="{{ $entry->url }}" wire:navigate
@else
    <span
@endif
        style="{{ $style }}"
        @class([$classes, 'opacity-60' => $entry->isProjected || $entry->isCompleted()])
        title="{{ $entry->title }} · {{ $time }}"
        data-agenda-entry="{{ $entry->key }}"
        data-agenda-source="{{ $entry->source }}"
        @if ($workspaceHex !== null) data-agenda-workspace="{{ $entry->workspace->friendly_id }}" @endif>
    @if ($workspaceHex !== null)
        <span aria-hidden="true" class="absolute top-1 right-1 size-1.5 rounded-full" style="background-color: {{ $workspaceHex }}"></span>
    @endif
    @if ($item['height'] * $minuteHeight < 40)
        <span class="truncate"><span @class(['font-semibold', 'line-through' => $entry->isCompleted(), 'text-danger' => $entry->isOverdue()])>{{ $entry->title }}</span><span class="text-secondary-label tabular-nums">, {{ $entry->startsAt->setTimezone($timezone)->format('H:i') }}</span></span>
    @else
        <span @class(['truncate font-semibold', 'line-through' => $entry->isCompleted(), 'text-danger' => $entry->isOverdue()])>{{ $entry->title }}</span>
        <span class="truncate text-secondary-label tabular-nums">{{ $time }}</span>
    @endif
@if ($entry->reminder !== null)
    </button>
@elseif ($entry->url !== null)
    </a>
@else
    </span>
@endif
