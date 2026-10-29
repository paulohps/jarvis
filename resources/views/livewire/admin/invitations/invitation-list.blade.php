{{--
    The invitations of the admin area: the invite form, then every invitation
    with its status. Only pending invitations offer resend and revoke.
--}}
<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    @if (filled($status))
        <p role="status" data-invitation-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    <x-ui.settings-section :title="__('Invite someone')"
                           :description="__('The invitation e-mail carries a link valid for 7 days. Accepting it makes them a member of the workspace with these modules switched on.')">
        <form wire:submit="invite" class="flex flex-col gap-5" novalidate>
            <x-input type="email" :label="__('E-mail')" wire:model="email" autocomplete="off" required />

            <x-select.native :label="__('Workspace')" wire:model="workspace" required data-invite-workspace>
                @if ($this->workspaceOptions->isEmpty())
                    <option value="">{{ __('Create a workspace first') }}</option>
                @endif
                @foreach ($this->workspaceOptions as $workspaceOption)
                    <option value="{{ $workspaceOption->friendly_id }}" wire:key="invite-workspace-{{ $workspaceOption->id }}">{{ $workspaceOption->name }}</option>
                @endforeach
            </x-select.native>

            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium text-label">{{ __('Language') }}</p>
                <x-ui.segmented-control name="locale"
                                        wire:model="locale"
                                        :label="__('Language')"
                                        :value="$locale"
                                        :options="$localeOptions" />
            </div>

            <fieldset class="flex flex-col gap-2">
                <legend class="mb-2 text-sm font-medium text-label">{{ __('Modules') }}</legend>

                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach ($this->moduleOptions as $slug => $name)
                        <x-checkbox wire:key="invite-module-{{ $slug }}"
                                    wire:model="modules"
                                    value="{{ $slug }}"
                                    :label="$name"
                                    data-invite-module="{{ $slug }}" />
                    @endforeach
                </div>

                @error('modules.*')
                    <p class="text-sm text-danger">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="flex justify-end">
                <x-button type="submit" :text="__('Send the invitation')" icon="paper-airplane" loading="invite" />
            </div>
        </form>
    </x-ui.settings-section>

    <x-ui.settings-section :title="__('Invitations')">
        @error('invitation')
            <p role="alert" class="text-sm text-danger">{{ $message }}</p>
        @enderror

        @if ($this->invitations->isEmpty())
            <x-ui.empty-state icon="envelope" :title="__('No invitations yet')" :description="__('Invite someone of the family with the form above.')" />
        @else
            <ul role="list" class="divide-y divide-separator">
                @foreach ($this->invitations as $invitation)
                    <li wire:key="invitation-{{ $invitation->id }}"
                        data-invitation="{{ $invitation->friendly_id }}"
                        data-invitation-status="{{ $invitation->status }}"
                        class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="truncate text-[0.9375rem] font-medium text-label">{{ $invitation->email }}</p>
                                <x-ui.status-badge :status="$invitation->status" />
                            </div>

                            <p class="text-[0.8125rem] text-secondary-label">
                                @if ($invitation->workspace !== null)
                                    <span class="inline-flex items-center gap-1" data-invitation-workspace="{{ $invitation->workspace->friendly_id }}">
                                        <span aria-hidden="true" class="size-2 rounded-full" style="background-color: {{ \App\Models\Workspace::hexOf($invitation->workspace->color) }}"></span>
                                        {{ $invitation->workspace->name }}
                                    </span>
                                @else
                                    {{ __('No workspace') }}
                                @endif
                                ·
                                {{ $localeOptions[$invitation->locale->slug] ?? $invitation->locale->name }}
                                ·
                                {{ $this->moduleNamesOf($invitation) }}
                                ·
                                @if ($invitation->accepted_at !== null)
                                    {{ __('Accepted') }} <x-ui.localized-date :value="$invitation->accepted_at" />
                                @else
                                    {{ __('Expires') }} <x-ui.localized-date :value="$invitation->expires_at" />
                                @endif
                            </p>
                        </div>

                        @if ($invitation->isPending())
                            <div class="flex shrink-0 items-center gap-2" data-invitation-actions>
                                <x-button :text="__('Resend')" icon="arrow-path" sm flat color="secondary"
                                          wire:click="resend({{ $invitation->id }})" loading="resend({{ $invitation->id }})" />

                                <x-ui.confirm-dialog :title="__('Revoke the invitation?')"
                                                     :description="__('The link in the e-mail stops working.')"
                                                     :confirm-label="__('Revoke')"
                                                     danger
                                                     wire:click="revoke({{ $invitation->id }})">
                                    <x-slot:trigger>
                                        <x-button :text="__('Revoke')" icon="no-symbol" sm flat color="red" />
                                    </x-slot:trigger>
                                </x-ui.confirm-dialog>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.settings-section>
</div>
