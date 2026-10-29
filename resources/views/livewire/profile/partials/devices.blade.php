{{--
    This browser first — installing the web app as an app, driven by the
    page script ($store.pwa); its push is under Notifications — then the
    user's devices registered for push: the phones signed in to the app and
    the browsers that turned push on. Revoking one signs it out of the API
    and stops its push.
--}}
<x-ui.inset-grouped-list :header="__('This browser')"
                         :footer="__('Installed, :app opens from your home screen full screen, like the app.', ['app' => config('app.name')])"
                         data-pwa-section>
    <x-ui.list-row icon="arrow-down-tray" :title="__('Install :app', ['app' => config('app.name')])">
        <p class="text-[0.8125rem] text-pretty text-secondary-label">
            <span x-show="$store.pwa.isInstalled" data-pwa-state="installed">{{ __('Installed on this device.') }}</span>
            <span x-show="! $store.pwa.isInstalled && $store.pwa.canPromptInstall" data-pwa-state="installable">{{ __('Open it from your home screen, full screen.') }}</span>
            <span x-show="! $store.pwa.isInstalled && $store.pwa.needsManualInstall" data-pwa-state="manual">{{ __('From the browser: Share, then “Add to Home Screen”.') }}</span>
            <span x-show="! $store.pwa.isInstalled && ! $store.pwa.canPromptInstall && ! $store.pwa.needsManualInstall" data-pwa-state="unsupported">{{ __('This browser is not offering the install right now. Already installed? Open it from your home screen.', ['app' => config('app.name')]) }}</span>
        </p>

        <x-slot:trailing>
            <x-button :text="__('Install')" icon="arrow-down-tray" sm
                      x-cloak x-show="! $store.pwa.isInstalled && $store.pwa.canPromptInstall"
                      x-on:click="$store.pwa.install()"
                      data-pwa-profile-install />
            <span x-cloak x-show="$store.pwa.isInstalled" class="text-[0.8125rem]">{{ __('Installed') }}</span>
        </x-slot:trailing>
    </x-ui.list-row>
</x-ui.inset-grouped-list>

@if ($this->devices->isEmpty())
    <x-ui.empty-state icon="device-phone-mobile"
                      :title="__('No devices')"
                      :description="__('Phones where you sign in to the Jarvis app, and browsers where you turn push on, appear here.')" />
@else
    <x-ui.inset-grouped-list :header="__('Devices')"
                             :footer="__('Revoking a device signs it out of the app and stops its push notifications.')"
                             data-device-list>
        @foreach ($this->devices as $device)
            <x-ui.list-row wire:key="device-{{ $device->id }}"
                           :icon="$device->isBrowser() ? 'globe-alt' : 'device-phone-mobile'"
                           :title="$device->name"
                           data-device="{{ $device->friendly_id }}"
                           data-device-platform="{{ $device->platform->slug }}">
                <p class="truncate text-[0.8125rem] text-secondary-label">
                    <span x-cloak x-show="$store.pwa.push.deviceId === @js($device->friendly_id)" class="font-medium text-tint">{{ __('This browser') }} · </span>
                    {{ __($device->platform->name) }} ·
                    {{ __('Last used') }}
                    <x-ui.localized-date :value="$device->lastUsedAt()" />
                </p>

                <x-slot:trailing>
                    <x-ui.confirm-dialog :title="__('Revoke :device?', ['device' => $device->name])"
                                         :description="$device->isBrowser() ? __('This browser gets no more notifications.') : __('The app on this device is signed out and gets no more notifications.')"
                                         :confirm-label="__('Revoke')"
                                         danger
                                         wire:click="revokeDevice({{ $device->id }})">
                        <x-slot:trigger>
                            <x-button :text="__('Revoke')" icon="no-symbol" sm flat color="red" />
                        </x-slot:trigger>
                    </x-ui.confirm-dialog>
                </x-slot:trailing>
            </x-ui.list-row>
        @endforeach
    </x-ui.inset-grouped-list>
@endif
