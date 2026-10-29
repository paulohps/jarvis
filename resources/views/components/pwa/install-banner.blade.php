{{--
    The install banner of the PWA (US-12.2): offered once the browser can
    install the app (Chrome, Edge and the Android browsers raise
    `beforeinstallprompt`; Safari on iPhone and iPad installs from its
    share sheet, so the banner explains the steps). The user installs,
    asks to be reminded in a number of days of their choice, or never
    again — the choice is the browser's, kept in its storage
    (resources/js/components/pwa.js). The profile offers the install
    again, whatever was chosen here.

    On a phone it sits above the tab bar; on a desktop it is a card in the
    bottom corner.
--}}
<div x-data="{ days: 7, showingSteps: false }"
     x-cloak
     x-show="$store.pwa.shouldOfferInstall"
     x-transition
     role="region"
     aria-label="{{ __('Install :app', ['app' => config('app.name')]) }}"
     data-pwa-install-banner
     class="glass glass-edge fixed inset-x-3 bottom-[calc(4.5rem+max(0.5rem,env(safe-area-inset-bottom)))] z-30 flex flex-col gap-3 rounded-sheet p-4 lg:inset-x-auto lg:right-6 lg:bottom-6 lg:w-96">
    <div class="flex items-start gap-3">
        <img src="{{ asset('icon-192.png') }}" alt="" width="48" height="48" class="size-12 shrink-0 rounded-control shadow-soft">

        <div class="min-w-0 flex-1">
            <p class="text-[0.9375rem] font-semibold text-label">{{ __('Install :app', ['app' => config('app.name')]) }}</p>
            <p class="text-[0.8125rem] text-secondary-label" x-show="! $store.pwa.needsManualInstall">{{ __('Open it from your home screen, full screen, with push notifications.') }}</p>
            <p class="text-[0.8125rem] text-secondary-label" x-show="$store.pwa.needsManualInstall">{{ __('Add it to your home screen to open it full screen and get push notifications.') }}</p>
        </div>

        <button type="button"
                x-on:click="$store.pwa.snooze(1)"
                aria-label="{{ __('Close') }}"
                title="{{ __('Close') }}"
                class="grid size-8 shrink-0 place-items-center rounded-control text-secondary-label transition-colors hover:bg-fill hover:text-label focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="x-mark" class="size-5" aria-hidden="true" />
        </button>
    </div>

    <ol x-cloak x-show="showingSteps" class="flex flex-col gap-1 rounded-card bg-fill px-4 py-3 text-[0.8125rem] text-label" data-pwa-install-steps>
        <li x-show="! $store.pwa.sharesFromAddressBar">{{ __('1. Tap Share (the square with an arrow) at the bottom of the screen.') }}</li>
        <li x-show="$store.pwa.sharesFromAddressBar">{{ __('1. Tap Share (the square with an arrow) at the right of the address bar.') }}</li>
        <li>{{ __('2. Scroll the list down and choose “Add to Home Screen”.') }}</li>
        <li>{{ __('3. Confirm with “Add”.') }}</li>
    </ol>

    <div class="flex flex-wrap items-center gap-2">
        <x-button :text="__('Install')" icon="arrow-down-tray" sm
                  x-show="! $store.pwa.needsManualInstall"
                  x-on:click="$store.pwa.install()"
                  data-pwa-install />

        <x-button :text="__('See how')" icon="arrow-down-tray" sm
                  x-show="$store.pwa.needsManualInstall"
                  x-on:click="showingSteps = ! showingSteps"
                  x-bind:aria-expanded="showingSteps.toString()"
                  data-pwa-install-steps-toggle />

        <label class="flex items-center gap-1.5 text-[0.8125rem] text-label">
            <x-button :text="__('Remind me in')" sm flat color="secondary" x-on:click="$store.pwa.snooze(days)" data-pwa-snooze />
            <input type="number" x-model.number="days" min="1" max="365" step="1" inputmode="numeric"
                   aria-label="{{ __('Days until the next reminder') }}"
                   class="w-16 rounded-control border-separator bg-system-background px-2 py-1 text-[0.8125rem] text-label focus:border-tint focus:ring-tint"
                   data-pwa-snooze-days>
            <span>{{ __('days') }}</span>
        </label>

        <x-button :text="__('Never')" sm flat color="secondary" x-on:click="$store.pwa.never()" class="ml-auto" data-pwa-never />
    </div>
</div>
