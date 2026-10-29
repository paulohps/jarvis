{{--
    The push banner of the PWA (US-5.2, on the web): offered once the
    install banner is out of the way — answered, or never shown by this
    browser — while push can be turned on here (set up on the server, the
    browser supports it, not blocked, not on yet). The user turns it on,
    asks to be reminded in a number of days of their choice, or never
    again; the choice is the browser's (resources/js/components/pwa.js).
    The profile offers push again, whatever was chosen here.
--}}
<div x-data="{ days: 7 }"
     x-cloak
     x-show="$store.pwa.shouldOfferPush"
     x-transition
     role="region"
     aria-label="{{ __('Turn on push notifications') }}"
     data-pwa-push-banner
     class="glass glass-edge fixed inset-x-3 bottom-[calc(4.5rem+max(0.5rem,env(safe-area-inset-bottom)))] z-30 flex flex-col gap-3 rounded-sheet p-4 lg:inset-x-auto lg:right-6 lg:bottom-6 lg:w-96">
    <div class="flex items-start gap-3">
        <span aria-hidden="true" class="grid size-12 shrink-0 place-items-center rounded-control bg-tint-fill text-on-tint shadow-soft">
            <x-icon name="bell-alert" class="size-6" />
        </span>

        <div class="min-w-0 flex-1">
            <p class="text-[0.9375rem] font-semibold text-label">{{ __('Turn on push notifications') }}</p>
            <p class="text-[0.8125rem] text-secondary-label">{{ __('Get your notifications in this browser, even with :app closed.', ['app' => config('app.name')]) }}</p>
        </div>

        <button type="button"
                x-on:click="$store.pwa.snoozePush(1)"
                aria-label="{{ __('Close') }}"
                title="{{ __('Close') }}"
                class="grid size-8 shrink-0 place-items-center rounded-control text-secondary-label transition-colors hover:bg-fill hover:text-label focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="x-mark" class="size-5" aria-hidden="true" />
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <x-button :text="__('Turn on')" icon="bell" sm x-on:click="$store.pwa.subscribe()" data-pwa-push-turn-on />

        <label class="flex items-center gap-1.5 text-[0.8125rem] text-label">
            <x-button :text="__('Remind me in')" sm flat color="secondary" x-on:click="$store.pwa.snoozePush(days)" data-pwa-push-snooze />
            <input type="number" x-model.number="days" min="1" max="365" step="1" inputmode="numeric"
                   aria-label="{{ __('Days until the next reminder') }}"
                   class="w-16 rounded-control border-separator bg-system-background px-2 py-1 text-[0.8125rem] text-label focus:border-tint focus:ring-tint"
                   data-pwa-push-snooze-days>
            <span>{{ __('days') }}</span>
        </label>

        <x-button :text="__('Never')" sm flat color="secondary" x-on:click="$store.pwa.neverOfferPush()" class="ml-auto" data-pwa-push-never />
    </div>
</div>
