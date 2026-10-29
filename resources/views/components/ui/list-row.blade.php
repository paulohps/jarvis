@php
    $tag = $href !== null ? 'a' : 'div';
@endphp

<li {{ $attributes->only(['wire:key', 'wire:sort:item']) }}>
    <{{ $tag }} @if ($href !== null) href="{{ $href }}" @endif
        {{ $attributes->except(['wire:key', 'wire:sort:item'])->class([
            'flex min-h-11 items-center gap-3 px-4 py-2.5 text-label',
            'transition-colors hover:bg-fill focus:outline-none focus-visible:bg-fill' => $href !== null,
        ]) }}>
        @isset($leading)
            <div class="shrink-0">{{ $leading }}</div>
        @elseif ($icon)
            <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center rounded-control bg-tint-fill text-on-tint">
                <x-icon :name="$icon" class="size-5" />
            </span>
        @endisset

        <div class="min-w-0 flex-1">
            @if (filled($title))
                <p class="truncate text-[0.9375rem]">{{ $title }}</p>
            @endif

            @if (filled($subtitle))
                <p class="truncate text-[0.8125rem] text-secondary-label">{{ $subtitle }}</p>
            @endif

            {{ $slot }}
        </div>

        @isset($trailing)
            <div class="flex shrink-0 items-center gap-2 text-[0.9375rem] text-secondary-label">{{ $trailing }}</div>
        @endisset

        @if ($chevron)
            <x-icon name="chevron-right" class="size-4 shrink-0 text-secondary-label" aria-hidden="true" />
        @endif
    </{{ $tag }}>
</li>
