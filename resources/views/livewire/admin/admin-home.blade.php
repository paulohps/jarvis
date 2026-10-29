{{--
    The start page of the admin area: one row per admin screen, gathered
    as the sidebar gathers them.
--}}
<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    @foreach ($this->groups() as $group)
        <x-ui.inset-grouped-list :header="count($group['sections']) > 1 ? $group['title'] : null" wire:key="admin-group-{{ $loop->index }}" data-admin-group>
            @foreach ($group['sections'] as $routeName => [$title, $subtitle, $icon])
                @if (Route::has($routeName))
                    <x-ui.list-row wire:key="admin-section-{{ $routeName }}"
                                   :href="route($routeName)"
                                   :title="$title"
                                   :subtitle="$subtitle"
                                   :icon="$icon"
                                   chevron
                                   data-admin-section="{{ $routeName }}"
                                   wire:navigate />
                @endif
            @endforeach
        </x-ui.inset-grouped-list>
    @endforeach
</div>
