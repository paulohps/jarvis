{{--
    Connects a number: its nickname, then the QR code to scan in WhatsApp
    (Linked devices), or the pairing code to type there when linking by
    phone number. The QR code (and the pairing code) swaps on its own
    (webhook + Echo) and is renewed every few seconds while the screen stays
    open; once connected the screen shows it.
--}}
<div class="mx-auto flex w-full max-w-xl flex-col gap-6">
    <a href="{{ route('whatsapp.index') }}" wire:navigate
       class="inline-flex items-center gap-1 self-start text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
        <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
        {{ __('WhatsApp') }}
    </a>

    @if (filled($error))
        <p role="alert" data-connect-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $error }}</p>
    @endif

    @if ($accountId === null)
        <form wire:submit="connect" class="flex flex-col gap-5 rounded-card bg-secondary-grouped-background p-6 shadow-soft" novalidate data-connect-form>
            <p class="text-[0.9375rem] text-pretty text-secondary-label">
                @if ($linkByCode)
                    {{ __('Give the number a nickname and type it, then type the code you get in WhatsApp on the phone that has it.') }}
                @else
                    {{ __('Give the number a nickname, then scan the QR code with the phone that has it.') }}
                @endif
            </p>

            <x-input :label="__('Nickname')" wire:model="nickname" maxlength="100" :placeholder="__('Personal, Work…')" required />

            @if ($linkByCode)
                <x-input type="tel" :label="__('Phone number')" wire:model="phoneNumber" maxlength="25" autocomplete="tel"
                         placeholder="+55 11 99999-0000" :hint="__('With the country code and area code.')" required data-phone-number />
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-button :text="$linkByCode ? __('Use QR code instead') : __('Link with phone number instead')"
                          :icon="$linkByCode ? 'qr-code' : 'device-phone-mobile'" flat color="secondary"
                          wire:click="$toggle('linkByCode')" data-toggle-link-by-code />

                @if ($linkByCode)
                    <x-button type="submit" :text="__('Get code')" icon="key" loading="connect" />
                @else
                    <x-button type="submit" :text="__('Show QR code')" icon="qr-code" loading="connect" />
                @endif
            </div>
        </form>
    @elseif ($this->isOpen())
        <x-ui.empty-state icon="check-circle"
                          data-connect-state="open"
                          :title="__(':nickname is connected', ['nickname' => $nickname])"
                          :description="__('Its contacts and groups are being synced; you can send messages from it now.')">
            <x-slot:actions>
                <x-ui.status-badge status="open" :label="__('Connected')" />
            </x-slot:actions>
        </x-ui.empty-state>
    @else
        <section wire:poll.{{ \App\Livewire\Whatsapp\ConnectAccount::RENEW_SECONDS }}s.visible="renew"
                 data-connect-state="{{ $status }}"
                 class="flex flex-col items-center gap-5 rounded-card bg-secondary-grouped-background p-6 text-center shadow-soft">
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold text-label">{{ $nickname }}</h2>
                <x-ui.status-badge :status="$status" />
            </div>

            @if ($linkByCode && $pairingNumber !== null)
                @if (filled($pairingCode))
                    <p class="font-mono text-4xl font-semibold tracking-[0.2em] text-label select-all"
                       aria-label="{{ __('Pairing code') }}" data-pairing-code>{{ $this->formattedPairingCode() }}</p>
                @else
                    <div class="grid h-16 w-64 place-items-center rounded-control bg-fill text-sm text-secondary-label" data-pairing-code-pending>
                        {{ __('Waiting for the code…') }}
                    </div>
                @endif

                <p class="text-[0.875rem] text-secondary-label">{{ __('For +:number', ['number' => $pairingNumber]) }}</p>

                <ol class="flex list-decimal flex-col gap-1 pl-5 text-left text-[0.875rem] text-secondary-label">
                    <li>{{ __('Open WhatsApp on the phone with this number.') }}</li>
                    <li>{{ __('Go to Settings → Linked devices → Link a device.') }}</li>
                    <li>{{ __('Tap "Link with phone number instead" and type this code.') }}</li>
                </ol>

                <div class="flex flex-wrap justify-center gap-2">
                    <x-button :text="__('New code')" icon="arrow-path" flat color="secondary" wire:click="requestPairingCode" loading="requestPairingCode" data-pairing-renew />
                    <x-button :text="__('Use QR code instead')" icon="qr-code" flat color="secondary" wire:click="useQrCode" data-use-qr-code />
                </div>
            @else
                @if (filled($qrCode))
                    <img src="{{ $qrCode }}" alt="{{ __('QR code to connect :nickname', ['nickname' => $nickname]) }}"
                         data-qr-code class="size-64 rounded-control" />
                @else
                    <div class="grid size-64 place-items-center rounded-control bg-fill text-sm text-secondary-label" data-qr-code-pending>
                        {{ __('Waiting for the QR code…') }}
                    </div>
                @endif

                <ol class="flex list-decimal flex-col gap-1 pl-5 text-left text-[0.875rem] text-secondary-label">
                    <li>{{ __('Open WhatsApp on the phone with this number.') }}</li>
                    <li>{{ __('Go to Settings → Linked devices → Link a device.') }}</li>
                    <li>{{ __('Point the phone at this QR code.') }}</li>
                </ol>

                <x-button :text="__('New QR code')" icon="arrow-path" flat color="secondary" wire:click="renew" loading="renew" data-qr-renew />

                @if ($linkByCode)
                    <form wire:submit="requestPairingCode" class="flex w-full flex-col gap-3 border-t border-separator pt-5 text-left" novalidate data-pairing-form>
                        <x-input type="tel" :label="__('Phone number')" wire:model="phoneNumber" maxlength="25" autocomplete="tel"
                                 placeholder="+55 11 99999-0000" :hint="__('With the country code and area code.')" required data-phone-number />
                        <div class="flex flex-wrap justify-end gap-2">
                            <x-button :text="__('Use QR code instead')" icon="qr-code" flat color="secondary" wire:click="useQrCode" />
                            <x-button type="submit" :text="__('Get code')" icon="key" loading="requestPairingCode" />
                        </div>
                    </form>
                @else
                    <x-button :text="__('Link with phone number instead')" icon="device-phone-mobile" flat color="secondary"
                              wire:click="$set('linkByCode', true)" data-toggle-link-by-code />
                @endif
            @endif
        </section>
    @endif
</div>
