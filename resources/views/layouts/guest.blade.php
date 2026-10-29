@props(['title' => null])

{{--
    The visitor screens (sign in, invitation, error pages): the Jarvis mark,
    the language switch for guests, the content centered over a soft tinted
    backdrop that the glass blurs and links to the legal pages.

    Props: `title` (tab title). Slots: default (usually an <x-ui.auth-card>).
--}}
<x-ui.document :title="$title">
    <x-ui.skip-link />

    <div aria-hidden="true" class="ambient-backdrop pointer-events-none fixed inset-0 -z-10"></div>

    <div class="flex min-h-dvh flex-col">
        <header class="glass glass-edge mx-3 mt-[calc(0.75rem+env(safe-area-inset-top))] flex min-h-14 items-center justify-between gap-3 rounded-sheet px-3 py-1.5 sm:mx-6">
            <x-ui.brand />

            @guest
                <x-ui.locale-switch />
            @endguest
        </header>

        <main id="main-content" tabindex="-1" {{ $attributes->class('flex flex-1 flex-col items-center justify-center px-4 py-10 focus:outline-none sm:px-8') }}>
            {{ $slot }}
        </main>

        <footer class="flex justify-center gap-4 px-4 pb-6 text-xs text-secondary-label">
            <a href="{{ route('legal.privacy') }}" class="hover:underline">{{ __('Privacy policy') }}</a>
            <a href="{{ route('legal.terms') }}" class="hover:underline">{{ __('Terms of use') }}</a>
        </footer>
    </div>
</x-ui.document>
