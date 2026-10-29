<section aria-labelledby="auth-card-heading" {{ $attributes->class('mx-auto flex w-full max-w-md flex-col gap-5') }}>
    <div class="glass-thick glass-edge flex flex-col gap-6 rounded-sheet p-6 sm:p-8">
        <header class="flex flex-col gap-1.5 text-center">
            <h1 id="auth-card-heading" class="text-2xl font-bold tracking-tight text-balance text-label">{{ $title }}</h1>

            @if (filled($description))
                <p class="text-sm text-pretty text-secondary-label">{{ $description }}</p>
            @endif
        </header>

        {{ $slot }}
    </div>

    @isset($footer)
        <div class="text-center text-sm text-secondary-label">
            {{ $footer }}
        </div>
    @endisset
</section>
