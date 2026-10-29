@props(['title', 'updatedAt'])

{{--
    A public legal page (privacy policy, terms of use) on the guest layout:
    the title, the date it was last changed and its sections in a wide glass
    card. The layout's footer links to both legal pages.

    Props: `title`, `updatedAt` (Y-m-d). Slots: default (the sections).
--}}
<x-layouts::guest :title="$title">
    <article aria-labelledby="legal-page-heading" class="glass-thick glass-edge mx-auto flex w-full max-w-2xl flex-col gap-6 rounded-sheet p-6 sm:p-8">
        <header class="flex flex-col gap-1.5">
            <h1 id="legal-page-heading" class="text-2xl font-bold tracking-tight text-balance text-label">{{ $title }}</h1>
            <p class="text-sm text-secondary-label">{{ __('Last updated on :date', ['date' => \Carbon\CarbonImmutable::parse($updatedAt)->locale(app()->getLocale())->isoFormat('LL')]) }}</p>
        </header>

        <div class="flex flex-col gap-5 text-sm leading-relaxed text-pretty text-label [&_h2]:text-base [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">
            {{ $slot }}
        </div>
    </article>
</x-layouts::guest>
