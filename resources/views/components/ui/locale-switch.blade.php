<x-dropdown position="bottom-end" width="sm" {{ $attributes }}>
    <x-slot:action>
        <button type="button"
                x-on:click="show = !show"
                x-bind:aria-expanded="show"
                aria-haspopup="menu"
                class="flex min-h-11 items-center gap-1.5 rounded-control px-2.5 text-sm font-medium text-label hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="language" class="size-5" aria-hidden="true" />
            <span>{{ $locales()[$current()] ?? $current() }}</span>
            <span class="sr-only">{{ __('Change the language') }}</span>
        </button>
    </x-slot:action>

    @foreach ($locales() as $locale => $name)
        <x-dropdown.items :href="route('locale.update', $locale)"
                          :text="$name"
                          :icon="$locale === $current() ? 'check' : null"
                          position="right"
                          :lang="str_replace('_', '-', $locale)"
                          :aria-current="$locale === $current() ? 'true' : null" />
    @endforeach
</x-dropdown>
