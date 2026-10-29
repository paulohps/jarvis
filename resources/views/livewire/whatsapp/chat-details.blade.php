{{--
    The "Details" tab of an open chat's side panel: a contact's number and
    blocking it, or a group's info, members and settings (ChatDetails).
--}}
@php
    $chat = $this->chat;
    $connected = $chat->account->isOpen();
    $isAdmin = $this->isAdmin;
@endphp

<div class="flex flex-col gap-5" data-chat-details="{{ $chatId }}" @if ($chat->isGroup() && ! $loaded) wire:init="load" @endif>
    @if ($notice !== null)
        <p role="status" class="rounded-control bg-success/10 px-3 py-2 text-sm text-success" data-chat-details-notice>{{ $notice }}</p>
    @endif
    @if ($error !== null)
        <p role="alert" class="rounded-control bg-danger/10 px-3 py-2 text-sm text-danger" data-chat-details-error>{{ $error }}</p>
    @endif

    @if (! $chat->isGroup())
        <section class="flex flex-col gap-1">
            <h3 class="text-sm font-semibold text-label">{{ $chat->displayName() }}</h3>
            <p class="text-sm text-secondary-label">{{ $chat->phoneNumber() ?? $chat->jid }}</p>
        </section>

        @if ($chat->ephemeral_seconds > 0)
            <p class="flex items-center gap-2 text-sm text-secondary-label" data-chat-details-ephemeral>
                <x-icon name="clock" class="size-4" aria-hidden="true" />
                {{ __('Disappearing messages: :timer', ['timer' => __(\App\Models\WhatsappTarget::EPHEMERAL_TIMERS[$chat->ephemeral_seconds] ?? '')]) }}
            </p>
        @endif

        <section class="flex flex-col gap-2">
            <x-button :text="$chat->isBlocked() ? __('Unblock :name', ['name' => $chat->displayName()]) : __('Block :name', ['name' => $chat->displayName()])"
                      icon="no-symbol" :color="$chat->isBlocked() ? 'secondary' : 'red'" flat wire:click="toggleBlock" :disabled="! $connected" loading="toggleBlock" data-chat-block />
            <p class="text-xs text-secondary-label">{{ $chat->isBlocked() ? __('Blocked: they cannot message or call this number.') : __('Blocked contacts cannot message or call this number. Blocking on the phone does not show here.') }}</p>
        </section>
    @elseif (! $connected)
        <p class="p-4 text-center text-sm text-secondary-label">{{ __('Connect the number to see and manage the group.') }}</p>
    @elseif (! $loaded)
        <div class="flex items-center justify-center gap-2 p-6 text-sm text-secondary-label">
            <x-icon name="arrow-path" class="size-5 animate-spin" aria-hidden="true" />{{ __('Loading the group from WhatsApp…') }}
        </div>
    @else
        <section class="flex flex-col gap-3" data-chat-group-info>
            <div class="flex items-center gap-3">
                <label @class(['relative shrink-0', 'cursor-pointer' => $isAdmin || ! $infoAdminsOnly])
                       @if ($isAdmin || ! $infoAdminsOnly) x-tooltip="{{ __('Change the group picture') }}" @endif data-chat-group-picture>
                    <x-whatsapp.avatar :name="$chat->displayName()" :picture="$chat->picture_url" />
                    @if ($isAdmin || ! $infoAdminsOnly)
                        <input type="file" wire:model="picture" accept="image/jpeg,image/png" class="sr-only">
                    @endif
                </label>
                <div class="min-w-0 flex-1">
                    <h3 class="truncate text-base font-semibold text-label">{{ $chat->name ?? __('Group') }}</h3>
                    <p class="text-xs text-secondary-label">{{ trans_choice('{1} 1 member|[2,*] :count members', count($members), ['count' => count($members)]) }}</p>
                </div>
                @if (($isAdmin || ! $infoAdminsOnly) && ! $editingInfo)
                    <x-whatsapp.chat-action size="sm" icon="pencil" :title="__('Edit the subject and description')" wire:click="editInfo" data-chat-group-edit />
                @endif
            </div>
            <div wire:loading wire:target="picture" class="text-xs text-secondary-label">{{ __('Uploading…') }}</div>

            @if ($editingInfo)
                <form wire:submit="saveInfo" class="flex flex-col gap-3" data-chat-group-info-form>
                    <x-input :label="__('Subject')" wire:model="subject" maxlength="{{ \App\Actions\Whatsapp\CreateWhatsappGroup::MAX_SUBJECT }}" data-chat-group-subject />
                    <x-textarea :label="__('Description')" wire:model="description" rows="3" maxlength="2048" data-chat-group-description />
                    <div class="flex justify-end gap-2">
                        <x-button type="button" :text="__('Cancel')" flat sm color="secondary" wire:click="$set('editingInfo', false)" />
                        <x-button type="submit" :text="__('Save')" sm icon="check" loading="saveInfo" />
                    </div>
                </form>
            @elseif (filled($chat->description))
                <p class="text-sm whitespace-pre-wrap text-label" data-chat-group-description-text>{{ $chat->description }}</p>
            @endif
        </section>

        <section class="flex flex-col gap-2">
            <h4 class="text-xs font-semibold tracking-wide text-secondary-label uppercase">{{ __('Disappearing messages') }}</h4>
            <x-ui.segmented-control name="chat-group-ephemeral" :label="__('Disappearing messages')" wire:model.live="ephemeral" :value="$ephemeral"
                                    :options="collect(\App\Models\WhatsappTarget::EPHEMERAL_TIMERS)->mapWithKeys(fn ($label, $seconds) => [(string) $seconds => __($label)])->all()"
                                    data-chat-group-ephemeral />
        </section>

        @if ($isAdmin)
            <section class="flex flex-col gap-3" data-chat-group-permissions>
                <h4 class="text-xs font-semibold tracking-wide text-secondary-label uppercase">{{ __('Group permissions') }}</h4>
                <x-toggle wire:model.live="adminsOnly" :label="__('Only admins send messages')" data-chat-group-admins-only />
                <x-toggle wire:model.live="infoAdminsOnly" :label="__('Only admins edit the group info')" data-chat-group-info-admins-only />
            </section>

            <section class="flex flex-col gap-2" data-chat-group-invite>
                <h4 class="text-xs font-semibold tracking-wide text-secondary-label uppercase">{{ __('Invite link') }}</h4>
                @if ($inviteLink !== null)
                    <div class="flex items-center gap-2 rounded-control bg-fill px-3 py-2" x-data="{ copied: false }">
                        <span class="min-w-0 flex-1 truncate text-sm text-label" data-chat-group-invite-link>{{ $inviteLink }}</span>
                        <x-whatsapp.chat-action size="sm" icon="clipboard-document" :title="__('Copy')" x-on:click="navigator.clipboard.writeText(@js($inviteLink)); copied = true; setTimeout(() => copied = false, 1500)" />
                        <span x-show="copied" x-cloak class="text-xs text-success">{{ __('Copied') }}</span>
                    </div>
                    <div>
                        <x-button :text="__('Reset the link')" icon="arrow-path" flat sm color="red" wire:click="revokeInviteLink" loading="revokeInviteLink" data-chat-group-invite-revoke />
                    </div>
                @else
                    <div>
                        <x-button :text="__('Show the invite link')" icon="link" flat sm color="secondary" wire:click="showInviteLink" loading="showInviteLink" data-chat-group-invite-show />
                    </div>
                @endif
            </section>
        @endif

        <section class="flex flex-col gap-1" data-chat-group-members>
            <div class="flex items-center justify-between">
                <h4 class="text-xs font-semibold tracking-wide text-secondary-label uppercase">{{ __('Members') }}</h4>
                @if ($isAdmin)
                    <x-button :text="__('Add')" icon="user-plus" flat xs color="secondary" wire:click="startAddMembers" data-chat-group-add />
                @endif
            </div>
            <ul role="list" class="flex flex-col">
                @foreach ($members as $member)
                    <li wire:key="member-{{ $member['jid'] }}" class="flex items-center gap-3 rounded-control px-2 py-1.5 hover:bg-fill" data-chat-group-member="{{ $member['jid'] }}">
                        <x-whatsapp.avatar size="sm" :name="$member['name']" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-label">{{ $member['name'] }}</span>
                            @if ($member['number'] !== null && $member['number'] !== $member['name'])
                                <span class="block truncate text-xs text-secondary-label">{{ $member['number'] }}</span>
                            @endif
                        </span>
                        @if ($member['admin'])
                            <span class="shrink-0 rounded-full bg-tint/15 px-2 py-0.5 text-xs font-medium text-tint" data-chat-group-admin>{{ __('Admin') }}</span>
                        @endif
                        @if ($isAdmin && ! $member['me'])
                            <x-dropdown position="bottom-end">
                                <x-slot:action>
                                    <x-whatsapp.chat-action size="sm" icon="ellipsis-vertical" :title="__('Member options')" x-on:click="show = !show" data-chat-group-member-menu="{{ $member['jid'] }}" />
                                </x-slot:action>
                                @if ($member['admin'])
                                    <x-dropdown.items :text="__('Dismiss as admin')" icon="arrow-down" wire:click="updateMember(@js($member['jid']), 'demote')" data-chat-group-demote />
                                @else
                                    <x-dropdown.items :text="__('Make group admin')" icon="shield-check" wire:click="updateMember(@js($member['jid']), 'promote')" data-chat-group-promote />
                                @endif
                                <x-dropdown.items :text="__('Remove from the group')" icon="user-minus" wire:click="updateMember(@js($member['jid']), 'remove')" data-chat-group-remove />
                            </x-dropdown>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section>
            <x-button :text="__('Leave the group')" icon="arrow-right-start-on-rectangle" flat color="red" wire:click="$set('showLeave', true)" data-chat-group-leave />
        </section>

        <x-modal wire="showAddMembers" :title="__('Add members')">
            <form wire:submit="addMembers" class="flex flex-col gap-4" novalidate data-chat-group-add-form>
                <x-ui.search-field name="chat-group-add-search" :placeholder="__('Search contacts')" wire:model.live.debounce.300ms="memberSearch" />
                <ul role="list" class="flex max-h-60 flex-col overflow-y-auto">
                    @forelse ($showAddMembers ? $this->addableContacts : [] as $contact)
                        <li wire:key="add-member-{{ $contact->friendly_id }}">
                            <label class="flex cursor-pointer items-center gap-3 rounded-control px-2 py-1.5 hover:bg-fill">
                                <input type="checkbox" value="{{ $contact->friendly_id }}" wire:model="pickedMembers" class="size-4 rounded border-separator text-tint focus:ring-tint" data-chat-group-pick="{{ $contact->friendly_id }}">
                                <span class="min-w-0 flex-1 truncate text-sm text-label">{{ $contact->displayName() }}</span>
                                <span class="shrink-0 text-xs text-secondary-label">{{ $contact->phoneNumber() }}</span>
                            </label>
                        </li>
                    @empty
                        <li class="p-3 text-center text-sm text-secondary-label">{{ __('No contacts found.') }}</li>
                    @endforelse
                </ul>
                <x-input :label="__('Or a number')" wire:model="memberNumber" inputmode="tel" placeholder="+55 11 99999-0000" data-chat-group-add-number />
                <div class="flex justify-end gap-2">
                    <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                    <x-button type="submit" :text="__('Add')" icon="user-plus" loading="addMembers" />
                </div>
            </form>
        </x-modal>

        <x-modal wire="showLeave" :title="__('Leave the group?')">
            <div class="flex flex-col gap-5">
                <p class="text-sm text-label">{{ __('The number leaves “:name” on WhatsApp. Its messages stay in Jarvis, archived.', ['name' => $chat->displayName()]) }}</p>
                <div class="flex justify-end gap-2">
                    <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                    <x-button :text="__('Leave')" color="red" wire:click="leave" loading="leave" data-chat-group-leave-confirm />
                </div>
            </div>
        </x-modal>
    @endif
</div>
