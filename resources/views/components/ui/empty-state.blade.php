<div {{ $attributes->class('flex flex-col items-center gap-3 rounded-card bg-secondary-grouped-background px-6 py-12 text-center shadow-soft') }}>
    <span aria-hidden="true" class="grid size-14 place-items-center rounded-full bg-primary-100 text-tint">
        <x-icon :name="$icon" class="size-7" />
    </span>

    <h2 class="text-lg font-semibold text-label">{{ $title }}</h2>

    @if (filled($description))
        <p class="max-w-md text-sm text-pretty text-secondary-label">{{ $description }}</p>
    @endif

    @isset($actions)
        <div class="mt-2 flex flex-wrap justify-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
