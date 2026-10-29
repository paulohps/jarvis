<div role="radiogroup"
     @if (filled($label)) aria-label="{{ $label }}" @endif
     {{ $attributes->whereDoesntStartWith('wire:model')->class('flex w-full rounded-control bg-fill p-0.5 sm:inline-flex sm:w-auto') }}>
    @foreach ($options as $option => $optionLabel)
        <label class="relative flex min-h-8 min-w-0 flex-1 sm:flex-auto cursor-pointer items-center justify-center rounded-[calc(var(--radius-control)-2px)] px-3 py-1 text-center text-[0.8125rem] font-medium text-label transition has-[:checked]:bg-segment-thumb has-[:checked]:font-semibold has-[:checked]:shadow-soft has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-tint">
            <input type="radio"
                   name="{{ $name }}"
                   value="{{ $option }}"
                   class="sr-only"
                   @checked($isSelected($option))
                   {{ $attributes->whereStartsWith('wire:model') }}>
            <span class="truncate">{{ $optionLabel }}</span>
        </label>
    @endforeach
</div>
