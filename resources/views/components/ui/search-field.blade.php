<div {{ $attributes->only('class')->class('relative') }}>
    <x-icon name="magnifying-glass" class="pointer-events-none absolute inset-y-0 left-2.5 my-auto size-4 text-secondary-label" aria-hidden="true" />

    <input type="search"
           name="{{ $name }}"
           placeholder="{{ $placeholder }}"
           aria-label="{{ $label ?? $placeholder }}"
           autocomplete="off"
           {{ $attributes->except('class')->class('block min-h-9 w-full rounded-control border-0 bg-fill py-2 pr-3 pl-8 text-[0.9375rem] text-label placeholder:text-secondary-label focus:ring-2 focus:ring-tint focus:outline-none') }}>
</div>
