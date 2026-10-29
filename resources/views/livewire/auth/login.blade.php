{{--
    The sign-in screen: e-mail and password, or Google. No sign-up link:
    accounts only come from invitations.
--}}
<x-ui.auth-card :title="__('Sign in to Jarvis')" :description="__('Access is by invitation only. Use the account your invitation created.')">
    @if (filled($status))
        <p role="status" class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @if (filled($googleError))
        <p role="alert" data-google-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $googleError }}</p>
    @endif

    <form wire:submit="login" class="flex flex-col gap-4" novalidate>
        <x-input type="email"
                 :label="__('E-mail')"
                 wire:model="email"
                 autocomplete="username"
                 autofocus
                 required />

        <x-password :label="__('Password')"
                    wire:model="password"
                    autocomplete="current-password"
                    required />

        <div class="flex items-center justify-between gap-3">
            <x-checkbox :label="__('Remember me')" wire:model="remember" />

            <a href="{{ route('password.request') }}" wire:navigate class="text-sm font-medium text-tint hover:underline">
                {{ __('Forgot your password?') }}
            </a>
        </div>

        <x-button type="submit" :text="__('Sign in')" class="w-full" loading="login" />
    </form>

    <x-ui.google-button :href="route('auth.google.redirect')" />
</x-ui.auth-card>
