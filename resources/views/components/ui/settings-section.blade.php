<section aria-labelledby="{{ $headingId() }}" {{ $attributes->class('flex flex-col gap-5 rounded-card bg-secondary-grouped-background p-5 shadow-soft sm:p-6') }}>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex min-w-0 flex-col gap-1">
            <h2 id="{{ $headingId() }}" class="text-lg font-semibold tracking-tight text-label">{{ $title }}</h2>

            @if (filled($description))
                <p class="text-sm text-pretty text-secondary-label">{{ $description }}</p>
            @endif
        </div>

        @isset($status)
            <div class="shrink-0">{{ $status }}</div>
        @endisset
    </header>

    {{ $slot }}

    @isset($actions)
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-separator pt-4">
            {{ $actions }}
        </div>
    @endisset
</section>
