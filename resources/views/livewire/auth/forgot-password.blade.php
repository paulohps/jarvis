{{--
    Asks for a password reset link. The same message follows every request.
--}}
<x-ui.auth-card :title="__('Forgot your password?')" :description="__('Enter your e-mail and we will send you a link to choose a new password.')">
    @if (filled($status))
        <p role="status" data-reset-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    <form wire:submit="sendLink" class="flex flex-col gap-4" novalidate>
        <x-input type="email"
                 :label="__('E-mail')"
                 wire:model="email"
                 autocomplete="username"
                 autofocus
                 required />

        <x-button type="submit" :text="__('Send the reset link')" class="w-full" loading="sendLink" />
    </form>

    <x-slot:footer>
        <a href="{{ route('login') }}" wire:navigate class="font-medium text-tint hover:underline">{{ __('Back to sign in') }}</a>
    </x-slot:footer>
</x-ui.auth-card>
