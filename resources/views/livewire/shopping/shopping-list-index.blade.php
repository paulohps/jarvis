{{--
    The shopping lists of the user's workspaces: the active ones, the
    recurring ones under "Recurring", or the archived ones under "Archived",
    each with how many items are still to pick. A new list is named in a sheet
    and opens right away; a recurring one is set up in its own sheet. With
    shopping in several workspaces, each list shows its workspace and the
    sheets ask for one.
--}}
@php
    $showsWorkspaces = $this->showsWorkspaces();
@endphp

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-ui.segmented-control name="filter"
                                wire:model.live="filter"
                                :label="__('Show')"
                                :value="$filter"
                                :options="$this->filterOptions()"
                                class="sm:min-w-64"
                                data-shopping-filter />

        @if ($filter === 'recurring')
            <x-button :text="__('New recurring list')" icon="plus" wire:click="createSchedule" data-shopping-schedule-create />
        @else
            <x-button :text="__('New list')" icon="plus" wire:click="create" data-shopping-create />
        @endif
    </div>

    @if (filled($status))
        <p role="status" data-shopping-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @if ($filter === 'recurring')
        @if ($this->schedules->isEmpty())
            <x-ui.empty-state icon="arrow-path"
                              :title="__('No recurring lists')"
                              :description="__('Make a list again on a schedule — the market every Friday — closed when you are done or at its deadline.')" />
        @else
            <x-ui.inset-grouped-list :footer="__('Each run is a list\'s deadline: until then it collects items, and when it closes the next one is made.')">
                @foreach ($this->schedules as $schedule)
                    @php
                        $openList = $schedule->openList();
                    @endphp
                    <x-ui.list-row wire:key="shopping-schedule-{{ $schedule->friendly_id }}" data-shopping-schedule="{{ $schedule->friendly_id }}">
                        <x-slot:leading>
                            <span aria-hidden="true" class="grid size-8 place-items-center rounded-control bg-tint-fill text-on-tint">
                                <x-icon name="arrow-path" class="size-5" />
                            </span>
                        </x-slot:leading>

                        <button type="button" wire:click="editSchedule('{{ $schedule->friendly_id }}')"
                                class="block w-full min-w-0 text-left focus:outline-none focus-visible:underline"
                                data-shopping-schedule-edit="{{ $schedule->friendly_id }}">
                            <span class="block truncate text-[0.9375rem]">{{ $schedule->name }}</span>
                            <span class="block truncate text-[0.8125rem] text-secondary-label">
                                {{ $this->ruleLabel($schedule) }}
                                · {{ $schedule->closes_automatically ? __('Closes at the deadline') : __('Closed by hand') }}
                            </span>
                            @if ($showsWorkspaces)
                                @include('livewire.shopping.partials.workspace-badge', ['workspace' => $schedule->workspace])
                            @endif
                        </button>

                        @if ($openList !== null)
                            <x-slot:trailing>
                                <a href="{{ route('shopping.show', $openList) }}" wire:navigate
                                   @class(['text-[0.8125rem] hover:underline', 'text-danger' => $openList->isOverdue(), 'text-tint' => ! $openList->isOverdue()])
                                   data-shopping-schedule-open="{{ $openList->friendly_id }}">
                                    <x-ui.localized-date :value="$openList->due_at" format="date" />
                                </a>
                            </x-slot:trailing>
                        @endif
                    </x-ui.list-row>
                @endforeach
            </x-ui.inset-grouped-list>
        @endif
    @elseif ($this->lists->isEmpty())
        <x-ui.empty-state icon="shopping-cart"
                          :title="$filter === 'archived' ? __('No archived lists') : __('No shopping lists')"
                          :description="$filter === 'archived' ? null : __('Create a list and share it with everyone at home.')" />
    @else
        <x-ui.inset-grouped-list>
            @foreach ($this->lists as $list)
                <x-ui.list-row wire:key="shopping-list-{{ $list->friendly_id }}"
                               :href="route('shopping.show', $list)"
                               wire:navigate
                               icon="shopping-cart"
                               :title="$list->name"
                               :subtitle="collect([
                                   $list->items_count === 0
                                       ? __('No items')
                                       : __(':open of :total to pick', ['open' => $list->unchecked_items_count, 'total' => $list->items_count]),
                                   $list->completed_at !== null ? __('Completed') : null,
                                   $list->isOverdue() ? __('Overdue') : null,
                               ])->filter()->join(' · ')"
                               chevron
                               data-shopping-list="{{ $list->friendly_id }}">
                    @if ($showsWorkspaces)
                        @include('livewire.shopping.partials.workspace-badge', ['workspace' => $list->workspace])
                    @endif

                    @if ($list->is_default)
                        <x-slot:trailing>
                            <x-ui.status-badge status="default" :label="__('Default')" tone="info" data-shopping-list-default />
                        </x-slot:trailing>
                    @endif
                </x-ui.list-row>
            @endforeach
        </x-ui.inset-grouped-list>
    @endif

    <x-modal wire="showScheduleForm" :title="$editingScheduleId === null ? __('New recurring list') : __('Edit recurring list')">
        <form wire:submit="saveSchedule" class="flex flex-col gap-5" novalidate data-shopping-schedule-form>
            <x-input :label="__('Name')" wire:model="scheduleName" maxlength="100" :placeholder="__('Market, Pharmacy…')" required />

            @if ($showsWorkspaces)
                <x-select.native :label="__('Workspace')" wire:model="scheduleWorkspace" data-shopping-schedule-workspace>
                    @foreach ($this->workspaceOptions as $workspaceId => $workspaceName)
                        <option value="{{ $workspaceId }}">{{ $workspaceName }}</option>
                    @endforeach
                </x-select.native>
            @endif

            <x-ui.recurrence-picker :form="$recurrence" />
            <p class="-mt-3 text-[0.8125rem] text-secondary-label">{{ __('The time of each run is the list\'s deadline.') }}</p>

            <x-toggle wire:model.live="closes_automatically" :label="__('Close the list by itself at its deadline')" data-shopping-schedule-auto-close />

            @unless ($closes_automatically)
                <x-toggle wire:model="waits_for_close" :label="__('Make the next list only after this one is closed')" data-shopping-schedule-waits />
            @endunless

            <x-select.native :label="__('The next list starts with')" wire:model.live="next_list_content" data-shopping-schedule-content>
                @foreach ($this->nextContentOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select.native>

            @if ($next_list_content === 'template_and_pending')
                <x-textarea :label="__('Template')" :hint="__('One item per line.')" wire:model="template" rows="5" data-shopping-schedule-template />
            @endif

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    @if ($editingScheduleId !== null)
                        <x-ui.confirm-dialog :title="__('Stop this recurring list?')"
                                             :description="__('No new list is made. The lists already made stay.')"
                                             :confirm-label="__('Stop')"
                                             danger
                                             wire:click="deleteSchedule('{{ $editingScheduleId }}')">
                            <x-slot:trigger>
                                <x-button type="button" :text="__('Stop')" icon="trash" flat color="red" data-shopping-schedule-delete />
                            </x-slot:trigger>
                        </x-ui.confirm-dialog>
                    @endif
                </div>

                <div class="flex gap-2">
                    <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                    <x-button type="submit" :text="__('Save')" icon="check" loading="saveSchedule" />
                </div>
            </div>
        </form>
    </x-modal>

    <x-modal wire="showForm" :title="__('New list')">
        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-shopping-list-form>
            <x-input :label="__('Name')" wire:model="name" maxlength="100" :placeholder="__('Groceries, Pharmacy…')" required />

            @if ($showsWorkspaces)
                <x-select.native :label="__('Workspace')" wire:model="workspace" data-shopping-list-workspace>
                    @foreach ($this->workspaceOptions as $workspaceId => $workspaceName)
                        <option value="{{ $workspaceId }}">{{ $workspaceName }}</option>
                    @endforeach
                </x-select.native>
            @endif

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Create')" icon="check" loading="save" />
            </div>
        </form>
    </x-modal>
</div>
