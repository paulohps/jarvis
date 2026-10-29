<div {{ $attributes }}>
    <x-dropdown position="{{ $expanded ? 'top-start' : 'bottom-end' }}" width="lg">
        <x-slot:action>
            <button type="button"
                    x-on:click="show = !show"
                    x-bind:aria-expanded="show"
                    aria-haspopup="menu"
                    @class([
                        'flex min-w-0 items-center gap-2.5 rounded-control text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                        'w-full p-1.5 hover:bg-fill' => $expanded,
                        'rounded-full p-0.5' => ! $expanded,
                    ])>
                <span aria-hidden="true" class="grid size-9 shrink-0 place-items-center rounded-full bg-tint-fill text-sm font-semibold text-on-tint">
                    {{ $user->initials() }}
                </span>

                @if ($expanded)
                    <span class="min-w-0 flex-1 sidebar-collapsed:sr-only">
                        <span class="block truncate text-sm font-semibold text-label">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-secondary-label">{{ $user->email }}</span>
                    </span>
                @endif

                <span class="sr-only">{{ __('Open the user menu') }}</span>
            </button>
        </x-slot:action>

        <x-slot:header>
            <div class="min-w-0 px-2 py-1.5">
                <p class="truncate text-sm font-semibold text-label">{{ $user->name }}</p>
                <p class="truncate text-xs text-secondary-label">{{ $user->email }}</p>
            </div>
        </x-slot:header>

        @if (Route::has('profile'))
            <x-dropdown.items :text="__('Profile')" icon="user-circle" :href="route('profile')" separator />
        @endif

        @if (Route::has('logout'))
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-dropdown.items type="submit" :text="__('Sign out')" icon="arrow-right-start-on-rectangle" separator />
            </form>
        @endif
    </x-dropdown>
</div>
