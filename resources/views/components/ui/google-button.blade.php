<div {{ $attributes->class('flex flex-col gap-4') }}>
    <div class="flex items-center gap-3 text-xs font-medium tracking-wide text-secondary-label uppercase" aria-hidden="true">
        <span class="h-px flex-1 bg-separator"></span>
        {{ __('or') }}
        <span class="h-px flex-1 bg-separator"></span>
    </div>

    <a href="{{ $href }}"
       data-google-sign-in
       class="flex min-h-11 items-center justify-center gap-2.5 rounded-control bg-secondary-grouped-background px-4 text-[0.9375rem] font-semibold text-label ring-1 ring-separator ring-inset hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
        <img src="{{ asset('images/google-g.svg') }}" alt="" aria-hidden="true" class="size-5">
        {{ __('Continue with Google') }}
    </a>
</div>
