{{--
    The users of the admin area: name, e-mail, last access and workspaces, with
    deactivate / reactivate for everyone but the super admin.
--}}
<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    @if (filled($status))
        <p role="status" data-user-status-message class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    <x-ui.settings-section :title="__('Users')"
                           :description="__('A deactivated user is signed out of the web and the apps at once and cannot sign in again. Their data stays.')">
        <x-slot:status>
            <x-button :text="__('Workspaces')" icon="user-group" sm flat color="secondary"
                      :href="route('admin.workspaces.index')" wire:navigate />
        </x-slot:status>

        @error('user')
            <p role="alert" class="text-sm text-danger">{{ $message }}</p>
        @enderror

        <ul role="list" class="divide-y divide-separator">
            @foreach ($this->users as $user)
                <li wire:key="user-{{ $user->id }}"
                    data-user="{{ $user->friendly_id }}"
                    data-user-status="{{ $user->isDeactivated() ? 'deactivated' : 'active' }}"
                    class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-[0.9375rem] font-medium text-label">{{ $user->name }}</p>

                            @if ($user->isSuperAdmin())
                                <x-ui.status-badge status="super_admin" :label="__('Super admin')" tone="info" />
                            @elseif ($user->isDeactivated())
                                <x-ui.status-badge status="deactivated" :label="__('Deactivated')" tone="danger" />
                            @else
                                <x-ui.status-badge status="active" :label="__('Active')" />
                            @endif
                        </div>

                        <p class="truncate text-[0.8125rem] text-secondary-label">{{ $user->email }}</p>

                        <p class="text-[0.8125rem] text-secondary-label">
                            {{ __('Last access') }}:
                            @if ($user->last_seen_at !== null)
                                <x-ui.localized-date :value="$user->last_seen_at" />
                            @else
                                {{ __('Never') }}
                            @endif
                        </p>

                        <div class="flex flex-wrap items-center gap-1.5" data-user-workspaces>
                            @forelse ($user->memberships as $membership)
                                <a href="{{ route('admin.workspaces.show', $membership->workspace) }}" wire:navigate
                                   wire:key="user-{{ $user->id }}-workspace-{{ $membership->workspace_id }}"
                                   data-user-workspace="{{ $membership->workspace->friendly_id }}"
                                   class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold text-label ring-1 ring-inset ring-separator hover:bg-fill">
                                    <span aria-hidden="true" class="size-2 rounded-full" style="background-color: {{ \App\Models\Workspace::hexOf($membership->workspace->color) }}"></span>
                                    {{ $membership->workspace->name }}
                                </a>
                            @empty
                                <span class="text-[0.8125rem] text-secondary-label">{{ __('No workspace') }}</span>
                            @endforelse
                        </div>
                    </div>

                    @unless ($user->isSuperAdmin())
                        <div class="flex shrink-0 items-center gap-2" data-user-actions>
                            @if ($user->isDeactivated())
                                <x-button :text="__('Reactivate')" icon="arrow-uturn-left" sm flat color="secondary"
                                          wire:click="reactivate({{ $user->id }})" loading="reactivate({{ $user->id }})" />
                            @else
                                <x-ui.confirm-dialog :title="__('Deactivate :name?', ['name' => $user->name])"
                                                     :description="__('They are signed out of the web and the apps now and cannot sign in until reactivated. Nothing is deleted.')"
                                                     :confirm-label="__('Deactivate')"
                                                     danger
                                                     wire:click="deactivate({{ $user->id }})">
                                    <x-slot:trigger>
                                        <x-button :text="__('Deactivate')" icon="no-symbol" sm flat color="red" />
                                    </x-slot:trigger>
                                </x-ui.confirm-dialog>
                            @endif
                        </div>
                    @endunless
                </li>
            @endforeach
        </ul>
    </x-ui.settings-section>
</div>
