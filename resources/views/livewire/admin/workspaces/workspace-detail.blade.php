{{--
    One workspace of the admin area: name and color, its members (module
    switches, own color, default, remove), adding a user, and deletion.
--}}
<div class="mx-auto flex w-full max-w-4xl flex-col gap-6" data-workspace-detail="{{ $workspace->friendly_id }}">
    <div>
        <x-button :text="__('Workspaces')" icon="chevron-left" sm flat color="secondary"
                  :href="route('admin.workspaces.index')" wire:navigate />
    </div>

    @if (filled($status))
        <p role="status" data-workspace-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    <x-ui.settings-section :title="__('Workspace')"
                           :description="__('Members who chose their own color keep seeing it.')">
        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-workspace-form>
            <x-input :label="__('Name')" wire:model="name" maxlength="{{ \App\Models\Workspace::MAX_NAME_LENGTH }}" autocomplete="off" required />

            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium text-label">{{ __('Color') }}</p>
                @include('livewire.admin.workspaces.partials.color-picker', ['name' => 'workspace-color', 'model' => 'color', 'legend' => __('Color')])

                @error('color')
                    <p class="text-sm text-danger">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex justify-end">
                <x-button type="submit" :text="__('Save')" icon="check" loading="save" />
            </div>
        </form>
    </x-ui.settings-section>

    @if ($this->providerColumns->isNotEmpty())
        <x-ui.settings-section :title="__('Chat channels')"
                               :description="__('The channels of the chat screen this workspace may use. A member uses a channel only when it is on here and for them, with the Chats module.')">
            <div class="flex flex-wrap gap-x-6 gap-y-3" data-workspace-channels>
                @foreach ($this->providerColumns as $provider)
                    <div wire:key="workspace-channel-{{ $provider->slug }}" data-workspace-channel="{{ $provider->slug }}" class="flex items-center gap-2">
                        <x-toggle wire:model.live="workspaceChannels.{{ $provider->slug }}" :label="$provider->name"
                                  :aria-label="__(':channel for the workspace', ['channel' => $provider->name])" />
                    </div>
                @endforeach
            </div>
        </x-ui.settings-section>
    @endif

    <x-ui.settings-section :title="__('Members')"
                           :description="__('A member only sees the modules switched on here, with the data of this workspace. Switching a module off keeps its data. The super admin has every module.')">
        @error('user')
            <p role="alert" class="text-sm text-danger">{{ $message }}</p>
        @enderror

        @error('modules')
            <p role="alert" class="text-sm text-danger">{{ $message }}</p>
        @enderror

        @if ($this->memberships->isEmpty())
            <x-ui.empty-state icon="users" :title="__('No members yet')" :description="__('Add someone with the form below.')" />
        @else
            <ul role="list" class="divide-y divide-separator">
                @foreach ($this->memberships as $membership)
                    @php($member = $membership->user)

                    <li wire:key="member-{{ $membership->id }}"
                        data-member="{{ $member->friendly_id }}"
                        class="flex flex-col gap-3 py-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="truncate text-[0.9375rem] font-medium text-label">{{ $member->name }}</p>

                                    @if ($member->isSuperAdmin())
                                        <x-ui.status-badge status="super_admin" :label="__('Super admin')" tone="info" />
                                    @elseif ($member->isDeactivated())
                                        <x-ui.status-badge status="deactivated" :label="__('Deactivated')" tone="danger" />
                                    @endif

                                    @if ($membership->is_default)
                                        <x-ui.status-badge status="default" :label="__('Default')" tone="neutral" data-member-default />
                                    @endif
                                </div>

                                <p class="truncate text-[0.8125rem] text-secondary-label">{{ $member->email }}</p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2" data-member-actions>
                                @unless ($membership->is_default)
                                    <x-button :text="__('Make default')" icon="star" sm flat color="secondary"
                                              wire:click="makeDefault({{ $membership->id }})" loading="makeDefault({{ $membership->id }})" />
                                @endunless

                                <x-ui.confirm-dialog :title="__('Remove :name?', ['name' => $member->name])"
                                                     :description="__('They stop seeing the data of this workspace. The data stays.')"
                                                     :confirm-label="__('Remove')"
                                                     danger
                                                     wire:click="removeMember({{ $membership->id }})">
                                    <x-slot:trigger>
                                        <x-button :text="__('Remove')" icon="user-minus" sm flat color="red" />
                                    </x-slot:trigger>
                                </x-ui.confirm-dialog>
                            </div>
                        </div>

                        @if ($member->isSuperAdmin())
                            <p class="text-[0.8125rem] text-secondary-label">{{ __('All modules') }}</p>
                        @else
                            <div class="flex flex-wrap gap-x-6 gap-y-3" data-member-modules>
                                @foreach ($this->moduleColumns as $module)
                                    <div wire:key="member-module-{{ $membership->id }}-{{ $module->slug }}"
                                         data-member-module="{{ $member->friendly_id }}.{{ $module->slug }}"
                                         class="flex items-center gap-2">
                                        <x-toggle wire:model.live="access.{{ $membership->id }}.{{ $module->slug }}"
                                                  :label="$this->moduleLabel($module)"
                                                  :aria-label="__(':module for :name', ['module' => $this->moduleLabel($module), 'name' => $member->name])" />
                                    </div>
                                @endforeach
                            </div>

                            @if ($this->providerColumns->isNotEmpty() && ($access[$membership->id][\App\Models\Module::CHATS] ?? false))
                                <div class="flex flex-col gap-1" data-member-channels="{{ $member->friendly_id }}">
                                    <p class="text-[0.8125rem] text-secondary-label">{{ __('Chat channels for :name', ['name' => $member->name]) }}</p>
                                    <div class="flex flex-wrap gap-x-6 gap-y-3">
                                        @foreach ($this->providerColumns as $provider)
                                            <div wire:key="member-channel-{{ $membership->id }}-{{ $provider->slug }}"
                                                 data-member-channel="{{ $member->friendly_id }}.{{ $provider->slug }}"
                                                 class="flex items-center gap-2">
                                                <x-toggle wire:model.live="memberChannels.{{ $membership->id }}.{{ $provider->slug }}"
                                                          :label="$provider->name"
                                                          :aria-label="__(':channel for :name', ['channel' => $provider->name, 'name' => $member->name])" />
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif

                        <div class="flex flex-col gap-1">
                            <p class="text-[0.8125rem] text-secondary-label">{{ __('Color :name sees it in', ['name' => $member->name]) }}</p>
                            @include('livewire.admin.workspaces.partials.color-picker', [
                                'name' => 'member-color-'.$membership->id,
                                'model' => 'memberColors.'.$membership->id,
                                'legend' => __('Color :name sees it in', ['name' => $member->name]),
                                'allowNone' => true,
                                'noneColor' => $workspace->color,
                                'live' => true,
                            ])
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.settings-section>

    <x-ui.settings-section :title="__('Add a member')"
                           :description="__('Their first workspace becomes their default. To bring someone without an account, invite them.')">
        @if ($this->candidates->isEmpty())
            <p class="text-sm text-secondary-label">{{ __('Every user is already a member.') }}</p>
        @else
            <form wire:submit="addMember" class="flex flex-col gap-5" novalidate data-add-member-form>
                <x-select.native :label="__('User')" wire:model="newMember" data-add-member-select>
                    <option value="">{{ __('Choose a user') }}</option>
                    @foreach ($this->candidates as $candidate)
                        <option value="{{ $candidate->friendly_id }}" wire:key="candidate-{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</option>
                    @endforeach
                </x-select.native>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium text-label">{{ __('Modules') }}</legend>

                    <div class="flex flex-wrap gap-x-6 gap-y-2">
                        @foreach ($this->moduleColumns as $module)
                            <x-checkbox wire:key="new-member-module-{{ $module->slug }}"
                                        wire:model="newMemberModules"
                                        value="{{ $module->slug }}"
                                        :label="$this->moduleLabel($module)"
                                        data-new-member-module="{{ $module->slug }}" />
                        @endforeach
                    </div>

                    @error('modules.*')
                        <p class="text-sm text-danger">{{ $message }}</p>
                    @enderror
                </fieldset>

                <div class="flex justify-end">
                    <x-button type="submit" :text="__('Add the member')" icon="user-plus" loading="addMember" />
                </div>
            </form>
        @endif
    </x-ui.settings-section>

    <x-ui.settings-section :title="__('Delete the workspace')"
                           :description="__('Only a workspace without data — shopping lists, agenda, invoices — can be deleted. Its members stay users.')">
        @error('workspace')
            <p role="alert" data-error="workspace" class="text-sm text-danger">{{ $message }}</p>
        @enderror

        <div class="flex justify-end">
            <x-ui.confirm-dialog :title="__('Delete :name?', ['name' => $workspace->name])"
                                 :description="__('This cannot be undone.')"
                                 :confirm-label="__('Delete')"
                                 danger
                                 wire:click="delete">
                <x-slot:trigger>
                    <x-button :text="__('Delete the workspace')" icon="trash" sm flat color="red" />
                </x-slot:trigger>
            </x-ui.confirm-dialog>
        </div>
    </x-ui.settings-section>
</div>
