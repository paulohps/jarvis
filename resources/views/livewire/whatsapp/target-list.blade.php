{{--
    The contacts and groups of one account: a tab for each, a search, the
    list (50 per page) with the ones added by hand marked, and the form to
    add one by hand or rename one added by hand.
--}}
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <a href="{{ route('whatsapp.accounts.index') }}" wire:navigate
       class="inline-flex items-center gap-1 self-start text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
        <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
        {{ __('WhatsApp accounts') }}
    </a>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <x-ui.segmented-control name="type"
                                :label="__('Show')"
                                :options="[\App\Models\WhatsappTargetType::CONTACT => __('Contacts'), \App\Models\WhatsappTargetType::GROUP => __('Groups')]"
                                :value="$type"
                                wire:model.live="type"
                                data-target-type />

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->account->isOpen())
                <x-button :text="__('Sync contacts and groups')" icon="arrow-path" flat color="secondary"
                          wire:click="resync" loading="resync" data-target-resync />
            @endif

            <x-button :text="$this->isGroups() ? __('Add group') : __('Add contact')" icon="plus" wire:click="create" data-target-create />
        </div>
    </div>

    @if ($this->account->last_synced_at !== null)
        <p class="-mt-3 text-[0.8125rem] text-secondary-label" data-target-last-sync>
            {{ __('Contacts synced :when', ['when' => $this->account->last_synced_at->diffForHumans()]) }}
        </p>
    @endif

    <x-ui.search-field wire:model.live.debounce.300ms="search"
                       :placeholder="$this->isGroups() ? __('Search by name or id') : __('Search by name or number')"
                       data-target-search />

    @if (filled($status))
        <p role="status" data-target-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @if (filled($error))
        <p role="alert" data-target-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $error }}</p>
    @endif

    @if ($this->targets->isEmpty())
        <x-ui.empty-state :icon="$this->isGroups() ? 'user-group' : 'user'"
                          :title="filled($search)
                              ? __('Nothing matches the search')
                              : ($this->isGroups() ? __('No groups yet') : __('No contacts yet'))"
                          :description="__('Sync the account to bring them from WhatsApp, or add one by hand.')" />
    @else
        <x-ui.inset-grouped-list :footer="trans_choice('{1} 1 in total|[2,*] :count in total', $this->targets->total(), ['count' => $this->targets->total()])">
            @foreach ($this->targets as $target)
                <x-ui.list-row wire:key="whatsapp-target-{{ $target->friendly_id }}"
                               data-whatsapp-target="{{ $target->friendly_id }}"
                               :title="$target->displayName()"
                               :subtitle="$target->phoneNumber() ?? $target->jid"
                               :icon="$this->isGroups() ? 'user-group' : 'user'">
                    @unless ($target->is_synced)
                        <span class="mt-0.5 inline-flex rounded-full bg-fill px-2 py-0.5 text-[0.75rem] text-secondary-label" data-target-manual>
                            {{ __('Added by hand') }}
                        </span>
                    @endunless

                    @unless ($target->is_synced)
                        <x-slot:trailing>
                            <div class="flex shrink-0 items-center gap-1">
                                <x-button.circle icon="pencil-square" flat color="secondary" sm :title="__('Rename')"
                                                 wire:click="edit('{{ $target->friendly_id }}')"
                                                 data-target-edit="{{ $target->friendly_id }}" />

                                <x-ui.confirm-dialog :title="__('Remove :name?', ['name' => $target->displayName()])"
                                                     :description="__('It leaves this account’s list.')"
                                                     :confirm-label="__('Remove')"
                                                     danger
                                                     wire:click="remove('{{ $target->friendly_id }}')">
                                    <x-slot:trigger>
                                        <x-button.circle icon="trash" flat color="red" sm :title="__('Remove')"
                                                         data-target-remove="{{ $target->friendly_id }}" />
                                    </x-slot:trigger>
                                </x-ui.confirm-dialog>
                            </div>
                        </x-slot:trailing>
                    @endunless
                </x-ui.list-row>
            @endforeach
        </x-ui.inset-grouped-list>

        {{ $this->targets->links() }}
    @endif

    <x-modal wire="showForm"
             :title="$editingId !== null
                 ? __('Rename')
                 : ($this->isGroups() ? __('Add group') : __('Add contact'))">
        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-target-form>
            @if ($this->isGroups() && $editingId !== null)
                <x-input :label="__('Group id')" wire:model="identifier" disabled data-target-identifier />
            @elseif ($this->isGroups())
                <x-input :label="__('Invite link or group id')" wire:model="identifier" maxlength="120" autocomplete="off"
                         placeholder="https://chat.whatsapp.com/…"
                         :hint="__('In WhatsApp: the group → Invite via link → Copy link. The number has to be in the group to send to it.')"
                         required data-target-identifier />

                <x-checkbox :label="__('Join the group with this number (when it is not in it yet)')" wire:model="joinGroup" data-target-join />
            @else
                <x-input type="tel" :label="__('Number')" wire:model="identifier" maxlength="25" autocomplete="tel"
                         placeholder="+55 11 99999-0000"
                         :hint="__('In international format, with the country code.')"
                         :disabled="$editingId !== null" required data-target-identifier />
            @endif

            <x-input :label="__('Name (optional)')" wire:model="name" maxlength="255" data-target-name />

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="save" />
            </div>
        </form>
    </x-modal>
</div>
