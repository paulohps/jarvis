{{--
    The screen of the reset link: the new password, twice.
--}}
<x-ui.auth-card :title="__('Choose a new password')">
    <form wire:submit="resetPassword" class="flex flex-col gap-4" novalidate>
        <x-input type="email"
                 :label="__('E-mail')"
                 wire:model="email"
                 autocomplete="username"
                 required />

        <x-password :label="__('New password')"
                    wire:model="password"
                    autocomplete="new-password"
                    required />

        <x-password :label="__('Confirm the new password')"
                    wire:model="password_confirmation"
                    autocomplete="new-password"
                    required />

        <x-button type="submit" :text="__('Save the new password')" class="w-full" loading="resetPassword" />
    </form>

    <x-slot:footer>
        <a href="{{ route('login') }}" wire:navigate class="font-medium text-tint hover:underline">{{ __('Back to sign in') }}</a>
    </x-slot:footer>
</x-ui.auth-card>
