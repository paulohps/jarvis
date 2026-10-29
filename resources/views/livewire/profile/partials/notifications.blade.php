{{--
    The push of this browser first (the PWA, driven by the page script
    $store.pwa), then the notification matrix of the profile: one row per
    type the user may receive, one switch per channel offered on the
    platform. A switch shows
    the user's choice or, without one, the default; a channel the user
    cannot use yet (WhatsApp without a number or an account to send from)
    is disabled.
--}}
<x-ui.inset-grouped-list :header="__('This browser')"
                         :footer="__('Push notifications reach this browser even with :app closed.', ['app' => config('app.name')])"
                         data-pwa-push-section>
    <x-ui.list-row icon="bell-alert" :title="__('Push notifications in this browser')">
        <p class="text-[0.8125rem] text-pretty text-secondary-label">
            @if (! \App\Support\Pwa\WebPush::isConfigured())
                <span data-pwa-push-state="not-set-up">{{ __('Not set up on the server yet.') }}</span>
            @else
                <span x-show="$store.pwa.push.state === 'subscribed'" data-pwa-push-state="subscribed">{{ __('On. Notifications arrive here even with :app closed.', ['app' => config('app.name')]) }}</span>
                <span x-show="$store.pwa.push.state === 'available'" data-pwa-push-state="available">{{ __('Off. Turn them on to get them here.') }}</span>
                <span x-show="$store.pwa.push.state === 'denied'" data-pwa-push-state="denied">{{ __('Blocked in the browser settings for this site.') }}</span>
                <span x-show="$store.pwa.push.state === 'needs-install'" data-pwa-push-state="needs-install">{{ __('On iPhone and iPad, install :app first.', ['app' => config('app.name')]) }}</span>
                <span x-show="$store.pwa.push.state === 'unsupported'" data-pwa-push-state="unsupported">{{ __('This browser does not support push notifications.') }}</span>
                <span x-show="$store.pwa.push.state === 'busy'" data-pwa-push-state="busy">{{ __('One moment…') }}</span>
                <span x-show="$store.pwa.push.state === 'failed'" class="text-danger" data-pwa-push-state="failed">{{ __('Could not turn them on. Try again.') }}</span>
            @endif
        </p>

        @if (\App\Support\Pwa\WebPush::isConfigured())
            <x-slot:trailing>
                <x-button :text="__('Turn on')" icon="bell" sm
                          x-cloak x-show="['available', 'failed'].includes($store.pwa.push.state)"
                          x-on:click="$store.pwa.subscribe()"
                          data-pwa-push-subscribe />
                <x-button :text="__('Turn off')" icon="bell-slash" sm flat color="secondary"
                          x-cloak x-show="$store.pwa.push.state === 'subscribed'"
                          x-on:click="$store.pwa.unsubscribe()"
                          data-pwa-push-unsubscribe />
            </x-slot:trailing>
        @endif
    </x-ui.list-row>
</x-ui.inset-grouped-list>

@if ($this->platformWhatsappAccount !== null || $this->whatsappAccounts->isNotEmpty() || $whatsapp_notification_account !== '' || $whatsapp_notification_phone !== '')
    <x-ui.settings-section :title="__('WhatsApp notifications')"
                           :description="__('Choose the number your notifications go to and, if you want, one of your numbers to send them from.')"
                           data-whatsapp-notification-settings>
        <form wire:submit="saveWhatsappNotifications" class="flex flex-col gap-4" novalidate>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-select.native :label="__('From')" wire:model="whatsapp_notification_account" name="account">
                    @if ($this->platformWhatsappAccount !== null)
                        <option value="">{{ __(':app (platform)', ['app' => config('app.name')]) }}{{ $this->platformWhatsappAccount->phone_number ? ' · '.$this->platformWhatsappAccount->phone_number : '' }}</option>
                    @else
                        <option value="">{{ __('Not sent through WhatsApp') }}</option>
                    @endif
                    @foreach ($this->whatsappAccounts as $option)
                        <option value="{{ $option->friendly_id }}" wire:key="notification-account-{{ $option->friendly_id }}">
                            {{ $option->nickname }}{{ $option->phone_number ? ' · '.$option->phone_number : '' }}
                        </option>
                    @endforeach
                </x-select.native>

                <div class="flex flex-col gap-2">
                    @if ($this->whatsappAccounts->whereNotNull('phone_number')->isNotEmpty())
                        <x-select.native :label="__('To')" wire:model.live="whatsapp_notification_to_account" name="to_account">
                            <option value="">{{ __('Another number') }}</option>
                            @foreach ($this->whatsappAccounts->whereNotNull('phone_number') as $option)
                                <option value="{{ $option->friendly_id }}" wire:key="notification-to-account-{{ $option->friendly_id }}">
                                    {{ $option->nickname }} · {{ $option->phone_number }}
                                </option>
                            @endforeach
                        </x-select.native>
                    @endif

                    @if ($whatsapp_notification_to_account === '')
                        <x-input :label="$this->whatsappAccounts->whereNotNull('phone_number')->isNotEmpty() ? null : __('To')"
                                 :aria-label="__('Number')"
                                 wire:model="whatsapp_notification_phone" name="phone" placeholder="+5511999990000" inputmode="tel" autocomplete="tel" />
                    @endif
                </div>
            </div>

            @foreach (['account', 'to_account', 'phone'] as $field)
                @error($field)
                    <p class="text-sm text-danger">{{ $message }}</p>
                @enderror
            @endforeach

            <div class="flex justify-end">
                <x-button type="submit" :text="__('Save')" icon="check" loading="saveWhatsappNotifications" data-whatsapp-notification-save />
            </div>
        </form>

        @if ($this->needsWhatsappVerification())
            <form wire:submit="verifyWhatsappCode" class="mt-4 flex flex-col gap-3 border-t border-separator pt-4" novalidate data-whatsapp-notification-verification>
                <p class="text-[0.875rem] text-secondary-label">
                    {{ __('Type the code we sent to :phone on WhatsApp. Notifications only go to the number once it is verified.', ['phone' => auth()->user()->whatsapp_notification_phone]) }}
                </p>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="sm:w-48">
                        <x-input :label="__('Code')" wire:model="whatsapp_notification_code" name="code"
                                 inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" />
                    </div>

                    <div class="flex gap-2">
                        <x-button type="submit" :text="__('Verify')" icon="shield-check" loading="verifyWhatsappCode" />
                        <x-button type="button" wire:click="sendWhatsappCode" :text="__('Send a new code')" flat color="secondary" loading="sendWhatsappCode" />
                    </div>
                </div>

                @error('code')
                    <p class="text-sm text-danger">{{ $message }}</p>
                @enderror
            </form>
        @endif
    </x-ui.settings-section>
@endif

@if ($this->notificationRows === [])
    <x-ui.empty-state icon="bell-slash"
                      :title="__('No notifications to choose')"
                      :description="__('The notifications you can turn on or off will appear here.')" />
@else
    <x-ui.inset-grouped-list :header="__('Notifications')"
                             :footer="__('Changes apply from the next notification sent. Switches marked “default” follow the standard setting until you change them.')"
                             data-notification-matrix>
        @foreach ($this->notificationRows as $row)
            <x-ui.list-row wire:key="notification-row-{{ $row['key'] }}"
                           :title="$row['label']"
                           :subtitle="$row['description']"
                           data-notification-type="{{ $row['key'] }}">
                <div class="mt-3 flex flex-wrap gap-x-6 gap-y-3">
                    @foreach ($this->notificationColumns as $channel)
                        @isset($row['channels'][$channel])
                            @php
                                $cell = $row['channels'][$channel];
                            @endphp

                            <div wire:key="notification-cell-{{ $row['key'] }}-{{ $channel }}"
                                 data-notification-cell="{{ $row['key'] }}.{{ $channel }}"
                                 data-notification-default="{{ $cell['isDefault'] ? 'true' : 'false' }}"
                                 class="flex flex-col gap-1">
                                <x-toggle wire:model.live="preferences.{{ $row['key'] }}.{{ $channel }}"
                                          :label="$this->channelLabel($channel)"
                                          :disabled="! $cell['isAvailable']" />

                                @if (! $cell['isAvailable'])
                                    <p class="text-[0.75rem] text-secondary-label">{{ __('Set up your WhatsApp notifications first.') }}</p>
                                @elseif ($cell['isDefault'])
                                    <p class="text-[0.75rem] text-secondary-label">{{ __('Default') }}</p>
                                @endif

                                @error("preferences.{$row['key']}.{$channel}")
                                    <p class="text-[0.75rem] text-danger">{{ $message }}</p>
                                @enderror
                            </div>
                        @endisset
                    @endforeach
                </div>
            </x-ui.list-row>
        @endforeach
    </x-ui.inset-grouped-list>
@endif
