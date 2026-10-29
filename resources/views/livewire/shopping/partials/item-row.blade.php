{{--
    One item of a shopping list (shopping-list-show): the round checkbox, the
    name and note that open the edit form, and — for the unchecked items of an
    active list — the handle that drags it to a new place (`wire:sort`).
--}}
@php($isChecked = $item->isChecked())
@php($isSortable = ! $isChecked && ! $isArchived)

<x-ui.list-row wire:key="shopping-item-{{ $item->friendly_id }}"
               :wire:sort:item="$isSortable ? $item->friendly_id : null"
               data-shopping-item="{{ $item->friendly_id }}"
               data-checked="{{ $isChecked ? 'true' : 'false' }}">
    <x-slot:leading>
        <button type="button"
                role="checkbox"
                aria-checked="{{ $isChecked ? 'true' : 'false' }}"
                aria-label="{{ $isChecked ? __('Uncheck :name', ['name' => $item->name]) : __('Check :name', ['name' => $item->name]) }}"
                wire:click="toggle('{{ $item->friendly_id }}')"
                wire:loading.attr="disabled"
                wire:target="toggle('{{ $item->friendly_id }}')"
                @disabled($isArchived)
                data-shopping-toggle="{{ $item->friendly_id }}"
                class="grid size-11 place-items-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-tint disabled:opacity-60">
            <span aria-hidden="true"
                  @class([
                      'grid size-6 place-items-center rounded-full border-2 transition',
                      'border-tint-fill bg-tint-fill text-on-tint' => $isChecked,
                      'border-separator' => ! $isChecked,
                  ])>
                @if ($isChecked)
                    <x-icon name="check" class="size-4" />
                @endif
            </span>
        </button>
    </x-slot:leading>

    <button type="button"
            wire:click="editItem('{{ $item->friendly_id }}')"
            @disabled($isArchived)
            class="block min-h-11 w-full min-w-0 text-left focus:outline-none focus-visible:underline"
            data-shopping-edit="{{ $item->friendly_id }}">
        <span @class(['block truncate text-[0.9375rem]', 'text-secondary-label line-through' => $isChecked])>{{ $item->name }}</span>

        @if (filled($item->quantity_note))
            <span @class(['block truncate text-[0.8125rem] text-secondary-label', 'line-through' => $isChecked])>{{ $item->quantity_note }}</span>
        @endif
    </button>

    @if ($isSortable)
        <x-slot:trailing>
            <span wire:sort:handle
                  role="img"
                  aria-label="{{ __('Drag to reorder :name', ['name' => $item->name]) }}"
                  data-shopping-drag="{{ $item->friendly_id }}"
                  class="grid size-11 cursor-grab touch-none place-items-center text-secondary-label active:cursor-grabbing">
                <x-icon name="bars-3" class="size-5" aria-hidden="true" />
            </span>
        </x-slot:trailing>
    @endif
</x-ui.list-row>
