{{--
    One reminder line, in the list and in the agenda. Expects $reminder, the
    $occurrence (null for a projected run of the agenda, which has no
    checkbox), its $dueAt, the $rowKey and the $dateFormat of the due date
    (`datetime` in the list, `time` under an agenda day). Its workspace shows
    in the color the reader sees it in when the agenda tells them apart.
--}}
@php
    $isProjected = $occurrence === null;
    $isCompleted = $occurrence?->completed_at !== null;
    $isOverdue = ! $isCompleted && $dueAt !== null && $dueAt->isPast();
    $repeatLabel = $this->repeatLabel($reminder);
    $workspaceHex = $this->workspaceHex($reminder->workspace);
@endphp

<x-ui.list-row wire:key="{{ $rowKey }}"
               :data-reminder-occurrence="$occurrence?->friendly_id"
               :data-reminder-projected="$isProjected ? $reminder->friendly_id : null">
    <x-slot:leading>
        @if ($isProjected)
            <span aria-hidden="true" class="grid size-11 place-items-center">
                <span class="size-6 rounded-full border-2 border-dashed border-separator"></span>
            </span>
        @else
            <button type="button"
                    role="checkbox"
                    aria-checked="{{ $isCompleted ? 'true' : 'false' }}"
                    aria-label="{{ $isCompleted ? __('Reopen :title', ['title' => $reminder->title]) : __('Complete :title', ['title' => $reminder->title]) }}"
                    wire:click="toggle('{{ $occurrence->friendly_id }}')"
                    wire:loading.attr="disabled"
                    wire:target="toggle('{{ $occurrence->friendly_id }}')"
                    data-reminder-toggle="{{ $occurrence->friendly_id }}"
                    class="grid size-11 place-items-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                <span aria-hidden="true"
                      @class([
                          'grid size-6 place-items-center rounded-full border-2 transition',
                          'border-tint-fill bg-tint-fill text-on-tint' => $isCompleted,
                          'border-danger' => $isOverdue,
                          'border-separator' => ! $isCompleted && ! $isOverdue,
                      ])>
                    @if ($isCompleted)
                        <x-icon name="check" class="size-4" />
                    @endif
                </span>
            </button>
        @endif
    </x-slot:leading>

    <button type="button" wire:click="edit('{{ $reminder->friendly_id }}')"
            class="block w-full min-w-0 text-left focus:outline-none focus-visible:underline"
            data-reminder-edit="{{ $reminder->friendly_id }}">
        <p @class(['truncate text-[0.9375rem]', 'text-secondary-label line-through' => $isCompleted, 'text-secondary-label' => $isProjected])>{{ $reminder->title }}</p>

        @if (filled($reminder->note))
            <p class="truncate text-[0.8125rem] text-secondary-label">{{ $reminder->note }}</p>
        @endif
    </button>

    <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[0.8125rem] text-secondary-label">
        @if ($workspaceHex !== null)
            <span class="inline-flex min-w-0 items-center gap-1" data-reminder-workspace="{{ $reminder->workspace->friendly_id }}">
                <span aria-hidden="true" class="size-2 rounded-sm" style="background-color: {{ $workspaceHex }}"></span>
                {{ $reminder->workspace->name }}
            </span>
        @endif

        @if ($dueAt !== null)
            <span @class(['inline-flex items-center gap-1', 'text-danger' => $isOverdue]) data-reminder-due>
                <x-icon :name="$dateFormat === 'time' ? 'clock' : 'calendar'" class="size-3.5" aria-hidden="true" />
                <x-ui.localized-date :value="$dueAt" :format="$dateFormat" />
            </span>
        @endif

        @if ($repeatLabel !== null)
            <span class="inline-flex items-center gap-1" data-reminder-repeat>
                <x-icon name="arrow-path" class="size-3.5" aria-hidden="true" />
                {{ $repeatLabel }}
            </span>
        @endif

        @if ($reminder->assignedTo !== null)
            <span class="inline-flex min-w-0 items-center gap-1" data-reminder-assignee="{{ $reminder->assignedTo->friendly_id }}">
                <x-icon name="user" class="size-3.5" aria-hidden="true" />
                {{ $reminder->assignedTo->name }}
            </span>
        @endif

        @if ($isCompleted)
            <span class="min-w-0" data-reminder-completed-by>
                {{ __('Completed by :name', ['name' => $occurrence->completedBy?->name ?? __('someone')]) }}
                · <x-ui.localized-date :value="$occurrence->completed_at" />
            </span>
        @endif
    </p>
</x-ui.list-row>
