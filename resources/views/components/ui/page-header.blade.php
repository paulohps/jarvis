<header {{ $attributes->class('flex flex-col gap-3 pb-6 sm:flex-row sm:items-end sm:justify-between') }}>
    <div class="flex min-w-0 flex-col gap-1">
        @isset($leading)
            {{ $leading }}
        @endisset

        <h1 class="text-[2.125rem] leading-tight font-bold tracking-tight text-balance break-words text-label">{{ $title }}</h1>

        @if (filled($subtitle))
            <p class="text-[0.9375rem] text-pretty text-secondary-label">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</header>
