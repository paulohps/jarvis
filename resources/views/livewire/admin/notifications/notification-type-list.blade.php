{{--
    The notification center of the admin area: the platform channels, then
    every registered type with its switches, each saved as it changes.
--}}
<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <x-ui.settings-section :title="__('Channels')"
                           :description="__('A channel switched off delivers no notification and leaves the preferences of every user.')">
        <ul role="list" class="divide-y divide-separator">
            @foreach ($this->platformChannels as $channel)
                <li wire:key="platform-channel-{{ $channel->slug }}"
                    data-platform-channel="{{ $channel->slug }}"
                    class="flex items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="text-[0.9375rem] text-label">{{ __($channel->name) }}</p>

                        @unless ($this->isDelivered($channel->slug))
                            <p class="text-[0.8125rem] text-secondary-label">{{ __('Not delivered yet') }}</p>
                        @endunless
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-2">
                        @if ($channel->slug === \App\Models\NotificationChannel::WHATSAPP)
                            <div class="w-full sm:w-64">
                                <x-select.native :label="__('Send from')" wire:model.live="whatsappAccount"
                                                 data-platform-whatsapp-account>
                                    <option value="">{{ __('Only from the users’ own accounts') }}</option>
                                    @foreach ($this->whatsappAccounts as $account)
                                        <option value="{{ $account->friendly_id }}" wire:key="platform-whatsapp-account-{{ $account->friendly_id }}">
                                            {{ $account->nickname }}{{ $account->phone_number ? ' · '.$account->phone_number : '' }} ({{ $account->user->name }})
                                        </option>
                                    @endforeach
                                </x-select.native>
                            </div>
                        @endif

                        <x-toggle wire:model.live="platformActive.{{ $channel->slug }}"
                                  :aria-label="__('Deliver through :channel', ['channel' => __($channel->name)])" />
                    </div>
                </li>
            @endforeach
        </ul>
    </x-ui.settings-section>

    @foreach ($this->types as $type)
        <x-ui.settings-section wire:key="notification-type-{{ $type['key'] }}"
                               data-notification-type="{{ $type['key'] }}"
                               :title="$type['label']"
                               :description="$type['description']">
            <x-slot:status>
                @if (! $type['isProvisioned'])
                    <x-ui.status-badge status="pending" :label="__('Not provisioned')" />
                @elseif ($active[$type['key']] ?? false)
                    <x-ui.status-badge status="active" :label="__('On')" />
                @else
                    <x-ui.status-badge status="paused" :label="__('Off')" tone="neutral" />
                @endif
            </x-slot:status>

            @if (! $type['isProvisioned'])
                <p class="text-[0.875rem] text-secondary-label">{{ __('Run notifications:provision to create its channels and texts. Until then it is never sent.') }}</p>
            @else
                <div class="flex flex-col gap-3 sm:flex-row sm:gap-8">
                    <x-toggle wire:model.live="active.{{ $type['key'] }}" :label="__('Send this notification')" />
                    <x-toggle wire:model.live="visibleInProfile.{{ $type['key'] }}" :label="__('Offer it in the profile')" />
                </div>

                <ul role="list" class="divide-y divide-separator rounded-control bg-fill/40">
                    @foreach ($type['channels'] as $slug)
                        <li wire:key="notification-type-{{ $type['key'] }}-{{ $slug }}"
                            data-type-channel="{{ $type['key'] }}.{{ $slug }}"
                            class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="text-[0.9375rem] font-medium text-label">{{ $this->channelLabel($slug) }}</p>

                                @unless ($platformActive[$slug] ?? false)
                                    <p class="text-[0.8125rem] text-secondary-label">{{ __('Switched off on the platform') }}</p>
                                @endunless
                            </div>

                            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                <x-toggle wire:model.live="channelActive.{{ $type['key'] }}.{{ $slug }}" :label="__('Delivers')" />
                                <x-toggle wire:model.live="enabledByDefault.{{ $type['key'] }}.{{ $slug }}" :label="__('On by default')" />

                                @if ($slug === \App\Models\NotificationChannel::MAIL)
                                    <div class="w-full sm:w-56">
                                        <x-select.native :label="__('Send through')" wire:model.live="mailService.{{ $type['key'] }}"
                                                         data-type-mail-service="{{ $type['key'] }}">
                                            <option value="">{{ __('Default mailer') }}</option>
                                            @foreach ($this->mailServices as $mailService)
                                                <option value="{{ $mailService->friendly_id }}" wire:key="type-{{ $type['key'] }}-mail-{{ $mailService->friendly_id }}">{{ $mailService->name }}</option>
                                            @endforeach
                                        </x-select.native>
                                    </div>
                                @endif
                                <x-button :text="__('Edit texts')" icon="pencil-square" sm flat color="secondary"
                                          :href="route('admin.notifications.edit', ['notificationType' => $type['key'], 'channel' => $slug])"
                                          wire:navigate />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.settings-section>
    @endforeach
</div>
