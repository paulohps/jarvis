{{--
    The user's WhatsApp numbers: status (live), nickname, number and last
    sync, with connect, reconnect and remove, plus a menu per number to
    rename it, set the proxy it goes out through, check its connection and, while connected, sync its contacts
    and groups or restart its connection.
--}}
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <x-ui.inset-grouped-list>
        <x-ui.list-row :title="__('Chats')" :subtitle="__('WhatsApp in a tab of its own, full screen.')" icon="chat-bubble-oval-left-ellipsis"
                       :href="route('whatsapp.chat')" target="_blank" rel="noopener" data-whatsapp-link="chat" />
        <x-ui.list-row :title="__('Messages')" :subtitle="__('Send now, or schedule once or on a recurring schedule.')" icon="chat-bubble-left-right"
                       :href="route('whatsapp.messages.index')" wire:navigate data-whatsapp-link="messages" />
        <x-ui.list-row :title="__('History')" :subtitle="__('Every message sent from your numbers, with its status.')" icon="clock"
                       :href="route('whatsapp.history.index')" wire:navigate data-whatsapp-link="history" />
    </x-ui.inset-grouped-list>

    <div class="flex flex-wrap items-center justify-end gap-3">
        <x-button :text="__('Connect a number')" icon="plus" :href="route('whatsapp.accounts.connect')" wire:navigate data-account-connect />
    </div>

    @if (filled($status))
        <p role="status" data-account-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @if (filled($error))
        <p role="alert" data-account-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $error }}</p>
    @endif

    @if ($this->accounts->isEmpty())
        <x-ui.empty-state icon="chat-bubble-left-right"
                          :title="__('No WhatsApp numbers yet')"
                          :description="__('Connect a number by QR code to send and schedule messages from it.')" />
    @else
        <x-ui.inset-grouped-list :footer="__('Only you see and use your numbers.')">
            @foreach ($this->accounts as $account)
                <x-ui.list-row wire:key="whatsapp-account-{{ $account->friendly_id }}"
                               data-whatsapp-account="{{ $account->friendly_id }}"
                               :title="$account->nickname"
                               :subtitle="$account->phone_number ?? __('Number not connected yet')"
                               icon="device-phone-mobile">
                    <div class="flex flex-wrap items-center gap-2 text-[0.8125rem] text-secondary-label">
                        <x-ui.status-badge :status="$account->status->slug" data-account-state="{{ $account->status->slug }}" />

                        @if ($account->last_synced_at !== null)
                            <span>{{ __('Contacts synced :when', ['when' => $account->last_synced_at->diffForHumans()]) }}</span>
                        @endif

                        @if ($account->hasProxy())
                            <span class="inline-flex items-center gap-1" data-account-proxy-badge="{{ $account->friendly_id }}">
                                <x-icon name="globe-alt" class="size-4" />
                                {{ __('via proxy :host', ['host' => $account->proxy_host.':'.$account->proxy_port]) }}
                            </span>
                        @endif

                        @if ($account->chats_synced_at !== null)
                            <span>{{ __('Conversations synced :when', ['when' => $account->chats_synced_at->diffForHumans()]) }}</span>
                        @endif

                        <a href="{{ route('whatsapp.accounts.targets', $account) }}" wire:navigate
                           class="text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                           data-account-targets-link="{{ $account->friendly_id }}">
                            {{ trans_choice('{0} No contacts or groups|{1} 1 contact or group|[2,*] :count contacts and groups', $account->targets_count, ['count' => $account->targets_count]) }}
                        </a>
                    </div>

                    <x-slot:trailing>
                        <div class="flex shrink-0 items-center gap-1">
                            @unless ($account->isOpen())
                                <x-button.circle icon="qr-code" flat color="secondary" sm
                                                 :title="__('Reconnect')"
                                                 :href="route('whatsapp.accounts.reconnect', $account)"
                                                 wire:navigate
                                                 data-account-reconnect="{{ $account->friendly_id }}" />
                            @endunless

                            <x-dropdown position="bottom-end" width="lg" data-account-actions="{{ $account->friendly_id }}">
                                <x-slot:action>
                                    <x-button.circle icon="ellipsis-horizontal" flat color="secondary" sm
                                                     :title="__('More actions')"
                                                     x-on:click="show = !show"
                                                     x-bind:aria-expanded="show"
                                                     aria-haspopup="menu" />
                                </x-slot:action>

                                <x-dropdown.items :text="__('Contacts and groups')" icon="users"
                                                  :href="route('whatsapp.accounts.targets', $account)"
                                                  wire:navigate
                                                  data-account-targets="{{ $account->friendly_id }}" />

                                @if ($account->isOpen())
                                    <x-dropdown.items :text="__('Sync contacts and groups')" icon="arrow-path"
                                                      wire:click="resync('{{ $account->friendly_id }}')"
                                                      data-account-resync="{{ $account->friendly_id }}" />
                                    <x-dropdown.items :text="__('Sync conversations')" icon="chat-bubble-left-right"
                                                      wire:click="syncConversations('{{ $account->friendly_id }}')"
                                                      data-account-sync-conversations="{{ $account->friendly_id }}" />
                                    <x-dropdown.items :text="__('Restart connection')" icon="bolt"
                                                      wire:click="restart('{{ $account->friendly_id }}')"
                                                      data-account-restart="{{ $account->friendly_id }}" />
                                @else
                                    <x-dropdown.items :text="__('Reconnect')" icon="qr-code"
                                                      :href="route('whatsapp.accounts.reconnect', $account)"
                                                      wire:navigate />
                                @endif

                                <x-dropdown.items :text="__('Check connection')" icon="signal"
                                                  wire:click="checkConnection('{{ $account->friendly_id }}')"
                                                  data-account-check="{{ $account->friendly_id }}" />
                                <x-dropdown.items :text="__('Proxy')" icon="globe-alt"
                                                  wire:click="startProxy('{{ $account->friendly_id }}')"
                                                  data-account-proxy="{{ $account->friendly_id }}" />
                                <x-dropdown.items :text="__('Rename')" icon="pencil-square"
                                                  wire:click="startRename('{{ $account->friendly_id }}')"
                                                  data-account-rename="{{ $account->friendly_id }}" />
                            </x-dropdown>

                            <x-ui.confirm-dialog :title="__('Remove :nickname?', ['nickname' => $account->nickname])"
                                                 :description="__('The number is logged out and its scheduled messages stop.')"
                                                 :confirm-label="__('Remove')"
                                                 danger
                                                 wire:click="remove('{{ $account->friendly_id }}')">
                                <x-slot:trigger>
                                    <x-button.circle icon="trash" flat color="red" sm :title="__('Remove')" />
                                </x-slot:trigger>
                            </x-ui.confirm-dialog>
                        </div>
                    </x-slot:trailing>
                </x-ui.list-row>
            @endforeach
        </x-ui.inset-grouped-list>
    @endif

    <x-modal wire="showRename" :title="__('Rename the number')">
        <form wire:submit="rename" class="flex flex-col gap-5" novalidate data-rename-form>
            <x-input :label="__('Nickname')" wire:model="nickname" maxlength="100" :placeholder="__('Personal, Work…')" required />

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="rename" />
            </div>
        </form>
    </x-modal>

    <x-modal wire="showProxy" :title="__('Proxy of the number')">
        <form wire:submit="saveProxy" class="flex flex-col gap-5" novalidate data-proxy-form>
            <p class="text-sm text-secondary-label">
                {{ __('The number connects to WhatsApp through this proxy, so it goes out with the proxy IP (e.g. your home, over a VPN). Before saving, WhatsApp checks that the proxy answers and changes the IP.') }}
            </p>

            <x-toggle wire:model.live="proxy.enabled" :label="__('Go out through a proxy')" data-proxy-enabled />

            @if ($proxy['enabled'])
                <x-select.native :label="__('Protocol')" wire:model="proxy.protocol" data-proxy-protocol>
                    @foreach (\App\Models\WhatsappAccount::PROXY_PROTOCOLS as $protocol)
                        <option value="{{ $protocol }}">{{ strtoupper($protocol) }}</option>
                    @endforeach
                </x-select.native>

                <div class="grid grid-cols-[1fr_8rem] gap-3">
                    <x-input :label="__('Address')" wire:model="proxy.host" placeholder="100.64.0.2" required data-proxy-host />
                    <x-input :label="__('Port')" wire:model="proxy.port" inputmode="numeric" placeholder="1080" required data-proxy-port />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <x-input :label="__('User (optional)')" wire:model="proxy.username" autocomplete="off" />
                    <x-password :label="__('Password (optional)')" wire:model="proxy.password" autocomplete="new-password"
                                :hint="__('Leave it blank to keep the saved one.')" />
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="saveProxy" />
            </div>
        </form>
    </x-modal>
</div>
