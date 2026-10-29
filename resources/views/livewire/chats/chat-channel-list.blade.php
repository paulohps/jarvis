{{--
    The chats module: the link to the chat screen and, per channel switched
    on for the user, their connected accounts with sync and disconnect, and
    how to connect one more.
--}}
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6" data-chat-channels>
    <x-ui.inset-grouped-list>
        <x-ui.list-row :title="__('Inbox')" :subtitle="__('Every channel in one screen.')" icon="inbox"
                       :href="route('chats.chat')" data-chats-link="chat" />
    </x-ui.inset-grouped-list>

    @if (filled($status))
        <p role="status" data-channels-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @if (filled($error))
        <p role="alert" data-channels-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $error }}</p>
    @endif

    @if ($this->providers->isEmpty())
        <x-ui.empty-state icon="chat-bubble-oval-left-ellipsis"
                          :title="__('No chat channel yet')"
                          :description="__('The administrator has not switched any channel on for you.')" />
    @endif

    @foreach ($this->providers as $provider)
        @php $accounts = $this->accounts->get($provider->slug) ?? collect(); @endphp
        <x-ui.settings-section wire:key="provider-{{ $provider->slug }}" :title="$provider->name"
                               :description="match ($provider->slug) {
                                   \App\Models\ChatProvider::SLACK => __('Connect each Slack workspace as yourself: Jarvis reads and writes your conversations as you, never as a bot.'),
                                   \App\Models\ChatProvider::WHATSAPP => __('The numbers connected on the WhatsApp screen: their chats show here as well as there, with the same pins, archives and unread marks.'),
                                   default => null,
                               }">
            <div data-chat-provider="{{ $provider->slug }}" class="flex flex-col gap-4">
                @if ($accounts->isEmpty())
                    <p class="text-sm text-secondary-label" data-provider-empty="{{ $provider->slug }}">{{ __('Nothing connected yet.') }}</p>
                @else
                    <x-ui.inset-grouped-list>
                        @foreach ($accounts as $account)
                            <x-ui.list-row wire:key="account-{{ $account->id }}" data-chat-account="{{ $account->id }}"
                                           :title="$account->label" :subtitle="$account->detail">
                                <x-slot:leading>
                                    <x-chats.provider-icon :provider="$provider->slug" class="size-8" />
                                </x-slot:leading>

                                <div class="flex flex-wrap items-center gap-2 text-[0.8125rem] text-secondary-label">
                                    @if ($account->isUsable)
                                        <x-ui.status-badge status="connected" :label="__('Connected')" tone="success" data-account-state="connected" />
                                    @else
                                        <x-ui.status-badge status="failed" :label="__('Needs reconnecting')" tone="danger" data-account-state="failed" />
                                        <span>{{ $account->error }}</span>
                                    @endif

                                    @if ($account->syncedAt !== null)
                                        <span>{{ __('Conversations synced :when', ['when' => $account->syncedAt->diffForHumans()]) }}</span>
                                    @else
                                        <span>{{ __('Syncing conversations…') }}</span>
                                    @endif

                                    <span>{{ trans_choice('{0} No conversations|{1} 1 conversation|[2,*] :count conversations', $account->conversationCount, ['count' => $account->conversationCount]) }}</span>
                                </div>

                                <x-slot:trailing>
                                    <div class="flex shrink-0 items-center gap-1">
                                        @if ($provider->slug === \App\Models\ChatProvider::SLACK)
                                            @unless ($account->isUsable)
                                                <x-button.circle icon="arrow-right-on-rectangle" flat color="secondary" sm :title="__('Connect again')"
                                                                 :href="route('chats.slack.redirect')" data-account-reconnect="{{ $account->id }}" />
                                            @endunless

                                            <x-dropdown position="bottom-end" width="lg" data-account-actions="{{ $account->id }}">
                                                <x-slot:action>
                                                    <x-button.circle icon="ellipsis-horizontal" flat color="secondary" sm :title="__('More actions')"
                                                                     x-on:click="show = !show" x-bind:aria-expanded="show" aria-haspopup="menu" />
                                                </x-slot:action>

                                                @if ($account->isUsable)
                                                    <x-dropdown.items :text="__('Sync conversations')" icon="arrow-path"
                                                                      wire:click="resyncSlack('{{ $account->id }}')" data-account-resync="{{ $account->id }}" />
                                                @endif
                                                @if ($account->url !== null)
                                                    <x-dropdown.items :text="__('Open on Slack')" icon="arrow-top-right-on-square" :href="$account->url" target="_blank" rel="noopener" />
                                                @endif
                                            </x-dropdown>

                                            <x-ui.confirm-dialog :title="__('Disconnect :workspace?', ['workspace' => $account->label])"
                                                                 :description="__('Its conversations leave the chat screen, with their tags and scheduled messages. The reminders stay.')"
                                                                 :confirm-label="__('Disconnect')"
                                                                 danger
                                                                 wire:click="disconnectSlack('{{ $account->id }}')">
                                                <x-slot:trigger>
                                                    <x-button.circle icon="trash" flat color="red" sm :title="__('Disconnect')" data-account-disconnect="{{ $account->id }}" />
                                                </x-slot:trigger>
                                            </x-ui.confirm-dialog>
                                        @elseif ($provider->slug === \App\Models\ChatProvider::WHATSAPP && $account->url !== null)
                                            <x-button.circle icon="arrow-top-right-on-square" flat color="secondary" sm :title="__('Manage on the WhatsApp screen')"
                                                             :href="$account->url" data-account-manage="{{ $account->id }}" />
                                        @endif
                                    </div>
                                </x-slot:trailing>
                            </x-ui.list-row>
                        @endforeach
                    </x-ui.inset-grouped-list>
                @endif

                @if ($provider->slug === \App\Models\ChatProvider::SLACK)
                    <div class="flex justify-end">
                        <x-button :text="__('Connect a Slack workspace')" icon="plus" :href="route('chats.slack.redirect')" data-slack-connect />
                    </div>
                @elseif ($provider->slug === \App\Models\ChatProvider::WHATSAPP && \Illuminate\Support\Facades\Route::has('whatsapp.accounts.connect'))
                    <div class="flex flex-wrap justify-end gap-2">
                        <x-button :text="__('WhatsApp screen')" icon="chat-bubble-left-right" flat color="secondary" :href="route('whatsapp.chat')" data-whatsapp-screen />
                        <x-button :text="__('Manage numbers')" icon="device-phone-mobile" flat color="secondary" :href="route('whatsapp.accounts.index')" data-whatsapp-numbers />
                        <x-button :text="__('Connect a WhatsApp number')" icon="plus" :href="route('whatsapp.accounts.connect')" data-whatsapp-connect />
                    </div>
                @endif
            </div>
        </x-ui.settings-section>
    @endforeach
</div>
