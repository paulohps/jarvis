<section {{ $attributes->class('flex flex-col gap-1.5') }}>
    @if (isset($header) && $header instanceof \Illuminate\View\ComponentSlot)
        <div class="px-4 text-[0.8125rem] text-secondary-label uppercase">{{ $header }}</div>
    @elseif (filled($header))
        <h2 class="px-4 text-[0.8125rem] font-normal text-secondary-label uppercase">{{ $header }}</h2>
    @endif

    <ul role="list" @if ($sort !== null) wire:sort="{{ $sort }}" @endif class="divide-y divide-separator overflow-hidden rounded-card bg-secondary-grouped-background shadow-soft">
        {{ $slot }}
    </ul>

    @if (isset($footer) && $footer instanceof \Illuminate\View\ComponentSlot)
        <div class="px-4 text-[0.8125rem] text-secondary-label">{{ $footer }}</div>
    @elseif (filled($footer))
        <p class="px-4 text-[0.8125rem] text-pretty text-secondary-label">{{ $footer }}</p>
    @endif
</section>
