@props(['title' => null, 'subtitle' => null])

@php
    $user = auth()->user();
    $navigation = $user instanceof \App\Models\User ? \App\Support\Navigation\AppNavigation::for($user) : [];
    $adminSections = $user instanceof \App\Models\User ? \App\Support\Navigation\AppNavigation::adminSectionsFor($user) : [];
    $sectionGroup = collect([...$navigation, ...$adminSections])->first(fn ($item) => $item->isGroup() && $item->activeChild() !== null);
@endphp

{{--
    The signed-in area (US-12.2): a translucent sidebar from 1024px — which
    collapses to its icons, remembered by the browser — a floating glass
    tab bar below it, a top bar (under the status bar once installed as an
    app) that turns to glass once the page scrolls, as on iOS 26 and 27,
    and the screen's iOS large title. The install banner of the PWA
    sits above the tab bar until the user answers it, and the push banner
    takes its place afterwards.

    Props: `title` (large title and tab title), `subtitle` (line under it).
    Slots: default (the screen), `header` (replaces the large title, e.g. a
    <x-ui.page-header> with actions). The top bar carries the notification
    bell (US-5.1) for the signed-in user.
--}}
<x-ui.document :title="$title">
    <x-ui.skip-link />

    <div x-data="{ scrolled: false }"
         x-on:scroll.window.passive="scrolled = window.scrollY > 48"
         class="min-h-dvh">
        <aside id="app-sidebar"
               class="glass glass-edge fixed inset-y-3 left-3 z-30 hidden w-64 flex-col gap-6 overflow-x-hidden overflow-y-auto rounded-sheet p-4 transition-[width,padding] duration-200 motion-reduce:transition-none lg:flex sidebar-collapsed:w-[4.5rem] sidebar-collapsed:px-2.5">
            <div class="flex items-center justify-between gap-2 sidebar-collapsed:flex-col sidebar-collapsed:gap-3">
                <x-ui.brand :href="route(\App\Support\Navigation\AppNavigation::HOME_ROUTE)" class="px-1 sidebar-collapsed:px-0" />

                <button type="button"
                        x-on:click="$store.sidebar.toggle()"
                        x-bind:aria-expanded="(! $store.sidebar.collapsed).toString()"
                        x-bind:aria-label="$store.sidebar.collapsed ? @js(__('Expand sidebar')) : @js(__('Collapse sidebar'))"
                        x-bind:title="$store.sidebar.collapsed ? @js(__('Expand sidebar')) : @js(__('Collapse sidebar'))"
                        aria-controls="app-sidebar"
                        aria-expanded="true"
                        aria-label="{{ __('Collapse sidebar') }}"
                        data-sidebar-toggle
                        class="grid size-8 shrink-0 place-items-center rounded-control text-secondary-label transition-colors hover:bg-fill hover:text-label focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                    <x-icon name="chevron-double-left" class="size-5 sidebar-collapsed:hidden" aria-hidden="true" />
                    <x-icon name="chevron-double-right" class="hidden size-5 sidebar-collapsed:block" aria-hidden="true" />
                </button>
            </div>

            <nav aria-label="{{ __('Main navigation') }}" class="flex-1">
                <ul role="list" class="flex flex-col gap-1">
                    @foreach ($navigation as $item)
                        @continue($item->key === 'admin')
                        <li>
                            @if ($item->isGroup())
                                <x-ui.navigation-group :item="$item" />
                            @else
                                <x-ui.navigation-link :item="$item" variant="sidebar" />
                            @endif
                        </li>
                    @endforeach
                </ul>
            </nav>

            @if ($adminSections !== [])
                <nav aria-labelledby="sidebar-admin-title" class="flex flex-col gap-1 border-t border-separator pt-4" data-navigation-admin>
                    <h2 id="sidebar-admin-title" class="px-3 pb-1 text-xs sidebar-collapsed:sr-only font-semibold tracking-wide text-secondary-label uppercase">
                        <a href="{{ route(\App\Support\Navigation\AppNavigation::ADMIN_ROUTE) }}" class="hover:text-label focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" data-navigation-item="admin">{{ __('Admin') }}</a>
                    </h2>
                    <ul role="list" class="flex flex-col gap-1">
                        @foreach ($adminSections as $item)
                            <li>
                                @if ($item->isGroup())
                                    <x-ui.navigation-group :item="$item" />
                                @else
                                    <x-ui.navigation-link :item="$item" variant="sidebar" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            @can('viewHorizon')
                <nav aria-labelledby="sidebar-tools-title" class="flex flex-col gap-1 border-t border-separator pt-4" data-navigation-tools>
                    <h2 id="sidebar-tools-title" class="px-3 pb-1 text-xs sidebar-collapsed:sr-only font-semibold tracking-wide text-secondary-label uppercase">{{ __('Tools') }}</h2>
                    <ul role="list" class="flex flex-col gap-1">
                        @foreach ([
                            ['key' => 'horizon', 'label' => __('Horizon'), 'icon' => 'queue-list', 'url' => route('horizon.index', ['view' => 'dashboard'])],
                        ] as $tool)
                            <li>
                                <a href="{{ $tool['url'] }}" target="_blank" rel="noopener"
                                   data-navigation-item="{{ $tool['key'] }}"
                                   title="{{ $tool['label'] }}"
                                   class="flex items-center gap-3 rounded-control px-3 py-2 text-sm font-medium text-label transition-colors hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint sidebar-collapsed:justify-center sidebar-collapsed:px-0">
                                    <x-icon :name="$tool['icon']" class="size-5 shrink-0" aria-hidden="true" />
                                    <span class="truncate sidebar-collapsed:sr-only">{{ $tool['label'] }}</span>
                                    <x-icon name="arrow-top-right-on-square" class="ml-auto size-4 shrink-0 text-secondary-label sidebar-collapsed:hidden" aria-hidden="true" />
                                    <span class="sr-only">{{ __('(opens in a new tab)') }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endcan

            <x-ui.user-menu expanded />
        </aside>

        <div class="flex min-h-dvh min-w-0 flex-col transition-[padding] duration-200 motion-reduce:transition-none lg:pl-[17.5rem] lg:sidebar-collapsed:pl-[6rem]">
            <header x-bind:class="scrolled ? 'glass border-separator' : 'border-transparent'"
                    class="sticky top-0 z-20 flex min-h-14 items-center gap-3 border-b border-transparent px-4 pt-[env(safe-area-inset-top)] transition-[background-color,border-color] duration-200 motion-reduce:transition-none lg:glass lg:top-3 lg:mt-3 lg:mr-3 lg:rounded-sheet lg:border lg:border-glass-border lg:pt-0 lg:shadow-soft">
                <x-ui.brand :href="route(\App\Support\Navigation\AppNavigation::HOME_ROUTE)" class="shrink-0 lg:hidden" />

                <p x-cloak
                   x-show="scrolled"
                   x-transition.opacity
                   aria-hidden="true"
                   class="min-w-0 flex-1 truncate text-center text-[1.0625rem] font-semibold text-label lg:text-left">
                    {{ $title }}
                </p>

                <div class="ml-auto flex shrink-0 items-center gap-2" data-slot="top-bar-actions">
                    @if ($user instanceof \App\Models\User)
                        <livewire:notifications.bell />
                    @endif

                    <x-ui.user-menu class="lg:hidden" />
                </div>
            </header>

            <main id="main-content"
                  tabindex="-1"
                  {{ $attributes->class('flex-1 px-4 pt-6 pb-[calc(7rem+env(safe-area-inset-bottom))] focus:outline-none sm:px-6 lg:pr-3 lg:pb-10') }}>
                @isset($header)
                    {{ $header }}
                @elseif (filled($title))
                    <x-ui.page-header :title="$title" :subtitle="$subtitle" />
                @endisset

                @if ($sectionGroup !== null)
                    <x-ui.section-tabs :group="$sectionGroup" />
                @endif

                {{ $slot }}
            </main>
        </div>

        @if ($user instanceof \App\Models\User)
            <x-pwa.install-banner />
            @if (\App\Support\Pwa\WebPush::isConfigured())
                <x-pwa.push-banner />
            @endif
        @endif

        <nav aria-label="{{ __('Main navigation') }}"
             class="pointer-events-none fixed inset-x-0 bottom-0 z-30 px-3 pb-[max(0.5rem,env(safe-area-inset-bottom))] lg:hidden">
            <ul role="list" class="glass-floating pointer-events-auto mx-auto flex max-w-xl gap-0.5 rounded-full p-1">
                @foreach ($navigation as $item)
                    <li class="min-w-0 flex-1"><x-ui.navigation-link :item="$item" variant="tab" /></li>
                @endforeach
            </ul>
        </nav>
    </div>
</x-ui.document>
