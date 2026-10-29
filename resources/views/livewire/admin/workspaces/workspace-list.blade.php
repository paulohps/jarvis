{{--
    The workspaces of the admin area: the create form, then every workspace
    with its color and member count, each opening its page.
--}}
<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    @if (filled($status))
        <p role="status" data-workspace-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    <x-ui.settings-section :title="__('New workspace')"
                           :description="__('A workspace is a group of people sharing shopping lists, the agenda and invoices. Add its members and their modules on its page.')">
        <form wire:submit="create" class="flex flex-col gap-5" novalidate data-workspace-create-form>
            <x-input :label="__('Name')" wire:model="name" maxlength="{{ \App\Models\Workspace::MAX_NAME_LENGTH }}" autocomplete="off" required />

            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium text-label">{{ __('Color') }}</p>
                @include('livewire.admin.workspaces.partials.color-picker', ['name' => 'new-workspace-color', 'model' => 'color', 'legend' => __('Color')])

                @error('color')
                    <p class="text-sm text-danger">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex justify-end">
                <x-button type="submit" :text="__('Create the workspace')" icon="plus" loading="create" />
            </div>
        </form>
    </x-ui.settings-section>

    <x-ui.settings-section :title="__('Workspaces')">
        @if ($this->workspaces->isEmpty())
            <x-ui.empty-state icon="user-group" :title="__('No workspaces yet')" :description="__('Create one with the form above.')" />
        @else
            <ul role="list" class="divide-y divide-separator">
                @foreach ($this->workspaces as $workspace)
                    <li wire:key="workspace-{{ $workspace->id }}" data-workspace="{{ $workspace->friendly_id }}">
                        <a href="{{ route('admin.workspaces.show', $workspace) }}" wire:navigate
                           class="flex items-center gap-3 py-3 hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                            <span aria-hidden="true" class="size-4 shrink-0 rounded-full" style="background-color: {{ \App\Models\Workspace::hexOf($workspace->color) }}"></span>

                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="truncate text-[0.9375rem] font-medium text-label">{{ $workspace->name }}</span>
                                <span class="text-[0.8125rem] text-secondary-label" data-workspace-members-count>
                                    {{ trans_choice(':count member|:count members', $workspace->memberships_count, ['count' => $workspace->memberships_count]) }}
                                </span>
                            </span>

                            <x-icon name="chevron-right" class="size-4 shrink-0 text-tertiary-label" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.settings-section>
</div>
