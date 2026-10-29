{{--
    One shopping list, for one hand in the market: the round checkbox of each
    item, the items to pick on top (dragged into any order by their handle),
    the checked ones in their own group below and struck through, "Clear checked",
    and the "Add item" field fixed at the bottom, above the tab bar. Changes
    made elsewhere arrive through Echo (ShoppingListChanged). With shopping in
    several workspaces, the list shows its workspace, and the rename sheet can
    move it to another.
--}}
@php
    $list = $this->list;
    $isArchived = $list->isArchived();
    $showsWorkspaces = $this->showsWorkspaces();
@endphp

<div class="mx-auto flex w-full max-w-3xl flex-col gap-4" data-shopping-list-screen="{{ $list->friendly_id }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <a href="{{ route('shopping.index', $isArchived ? ['filter' => 'archived'] : []) }}" wire:navigate
           class="inline-flex min-h-11 items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ $isArchived ? __('Archived') : __('Lists') }}
        </a>

        <div class="flex items-center gap-1">
            <x-button type="button" :text="__('Rename')" icon="pencil" flat color="secondary" wire:click="editList" data-shopping-rename />

            @if ($isArchived)
                <x-button type="button" :text="__('Unarchive')" icon="arrow-uturn-left" flat color="secondary" wire:click="unarchive" data-shopping-unarchive />
            @else
                <x-button type="button"
                          :text="$list->is_default ? __('Default list') : __('Make default')"
                          icon="star"
                          flat
                          :color="$list->is_default ? 'primary' : 'gray'"
                          wire:click="toggleDefault"
                          :title="$list->is_default ? __('Voice commands that name no list add here. Tap to unset.') : __('Voice commands that name no list will add here.')"
                          aria-pressed="{{ $list->is_default ? 'true' : 'false' }}"
                          data-shopping-default="{{ $list->is_default ? 'on' : 'off' }}" />

                @if ($list->due_at !== null)
                    <x-button type="button" :text="__('Complete')" icon="check-circle" flat color="primary" wire:click="complete" data-shopping-complete />
                @else
                    <x-button type="button" :text="__('Archive')" icon="archive-box" flat color="secondary" wire:click="archive" data-shopping-archive />
                @endif
            @endif

            <x-ui.confirm-dialog :title="__('Delete this list?')"
                                 :description="__('It leaves the lists for everyone at home.')"
                                 :confirm-label="__('Delete')"
                                 danger
                                 wire:click="delete">
                <x-slot:trigger>
                    <x-button type="button" :text="__('Delete')" icon="trash" flat color="red" data-shopping-delete />
                </x-slot:trigger>
            </x-ui.confirm-dialog>
        </div>
    </div>

    @if ($showsWorkspaces)
        <div>
            @include('livewire.shopping.partials.workspace-badge', ['workspace' => $list->workspace])
        </div>
    @endif

    @if ($list->due_at !== null && ! $isArchived)
        <p @class([
               'flex items-center gap-2 rounded-control px-4 py-3 text-sm',
               'bg-danger/10 text-danger' => $list->isOverdue(),
               'bg-fill text-secondary-label' => ! $list->isOverdue(),
           ])
           data-shopping-deadline>
            <x-icon name="arrow-path" class="size-4 shrink-0" aria-hidden="true" />
            <span>
                {{ $list->isOverdue() ? __('Overdue since') : __('Recurring list, until') }}
                <x-ui.localized-date :value="$list->due_at" />
            </span>
        </p>
    @endif

    @if ($list->completed_at !== null)
        <p role="status" class="rounded-control bg-success/10 px-4 py-3 text-sm text-success" data-shopping-completed-notice>
            {{ $list->completedBy === null ? __('Closed at its deadline') : __('Completed by :name', ['name' => $list->completedBy->name]) }}
            · <x-ui.localized-date :value="$list->completed_at" />
        </p>
    @elseif ($isArchived)
        <p role="status" class="rounded-control bg-fill px-4 py-3 text-sm text-secondary-label" data-shopping-archived-notice>
            {{ __('This list is archived. Unarchive it to change its items.') }}
        </p>
    @endif

    @error('list')
        <p role="alert" data-shopping-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    @if ($this->items->isEmpty())
        <x-ui.empty-state icon="shopping-cart"
                          :title="__('The list is empty')"
                          :description="$isArchived ? null : __('Add the first item below.')" />
    @else
        @php
            [$checkedItems, $uncheckedItems] = $this->items->partition(fn ($item) => $item->isChecked());
        @endphp

        @if ($uncheckedItems->isNotEmpty())
            <x-ui.inset-grouped-list :sort="$isArchived ? null : 'reorder'" data-shopping-unchecked>
                @foreach ($uncheckedItems as $item)
                    @include('livewire.shopping.partials.item-row')
                @endforeach
            </x-ui.inset-grouped-list>
        @endif

        @if ($checkedItems->isNotEmpty())
            <x-ui.inset-grouped-list :header="__('Checked (:count)', ['count' => $checkedItems->count()])" data-shopping-checked>
                @foreach ($checkedItems as $item)
                    @include('livewire.shopping.partials.item-row')
                @endforeach
            </x-ui.inset-grouped-list>
        @endif

        @if (! $isArchived && $this->checkedCount > 0)
            <x-ui.confirm-dialog :title="__('Clear checked items?')"
                                 :description="trans_choice('{1} The checked item leaves the list.|[2,*] The :count checked items leave the list.', $this->checkedCount, ['count' => $this->checkedCount])"
                                 :confirm-label="__('Clear')"
                                 danger
                                 wire:click="clearChecked"
                                 class="self-end">
                <x-slot:trigger>
                    <x-button type="button" :text="__('Clear checked')" icon="trash" flat color="red" data-shopping-clear-checked />
                </x-slot:trigger>
            </x-ui.confirm-dialog>
        @endif
    @endif

    @if (! $isArchived)
        <form wire:submit="addItem"
              novalidate
              data-shopping-add-item
              class="glass sticky bottom-[calc(4.5rem+max(0.5rem,env(safe-area-inset-bottom)))] z-10 -mx-1 mt-2 flex items-start gap-2 rounded-card border border-glass-border p-2 shadow-soft lg:bottom-4">
            <div class="grid min-w-0 flex-1 grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-2">
                <x-input wire:model="itemName" :placeholder="__('Add item')" :aria-label="__('Add item')" maxlength="255" autocomplete="off" enterkeyhint="done" data-shopping-item-name />
                <x-input wire:model="itemNote" :placeholder="__('Qty')" :aria-label="__('Quantity or note')" maxlength="255" autocomplete="off" enterkeyhint="done" data-shopping-item-note />
            </div>

            <x-button type="submit" icon="plus" :aria-label="__('Add item')" loading="addItem" class="min-h-11 min-w-11" data-shopping-item-submit />
        </form>
    @endif

    <x-modal wire="showItemForm" :title="__('Edit item')">
        <form wire:submit="updateItem" class="flex flex-col gap-5" novalidate data-shopping-item-form>
            <x-input :label="__('Name')" wire:model="editName" maxlength="255" required />
            <x-input :label="__('Quantity or note')" wire:model="editNote" maxlength="255" />

            <div class="flex flex-wrap items-center justify-between gap-2">
                <x-button type="button" :text="__('Remove')" icon="trash" flat color="red" wire:click="removeItem" data-shopping-item-remove />

                <div class="flex gap-2">
                    <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                    <x-button type="submit" :text="__('Save')" icon="check" loading="updateItem" />
                </div>
            </div>
        </form>
    </x-modal>

    <x-modal wire="showRenameForm" :title="__('Rename list')">
        <form wire:submit="renameList" class="flex flex-col gap-5" novalidate data-shopping-rename-form>
            <x-input :label="__('Name')" wire:model="listName" maxlength="100" required />

            @if ($showsWorkspaces)
                <x-select.native :label="__('Workspace')" wire:model="listWorkspace" data-shopping-rename-workspace>
                    @foreach ($this->workspaceOptions as $workspaceId => $workspaceName)
                        <option value="{{ $workspaceId }}">{{ $workspaceName }}</option>
                    @endforeach
                </x-select.native>
            @endif

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="renameList" />
            </div>
        </form>
    </x-modal>
</div>
