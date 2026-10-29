{{--
    The page of the invitation link: the invited e-mail (locked), a name and
    a password, or Google with that same e-mail.
--}}
<x-ui.auth-card :title="__('Accept the invitation')" :description="__('Choose your name and a password to join Jarvis.')">
    @if (filled($googleError))
        <p role="alert" data-google-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $googleError }}</p>
    @endif

    <form wire:submit="accept" class="flex flex-col gap-4" novalidate>
        <x-input type="email"
                 :label="__('E-mail')"
                 :value="$email"
                 name="email"
                 autocomplete="username"
                 readonly
                 disabled
                 data-invited-email />

        <x-input :label="__('Name')"
                 wire:model="name"
                 autocomplete="name"
                 autofocus
                 required />

        <x-password :label="__('Password')"
                    :hint="__('At least 8 characters.')"
                    wire:model="password"
                    autocomplete="new-password"
                    required />

        <x-password :label="__('Confirm the password')"
                    wire:model="password_confirmation"
                    autocomplete="new-password"
                    required />

        <x-button type="submit" :text="__('Create my account')" class="w-full" loading="accept" />
    </form>

    <x-ui.google-button :href="$googleUrl" />
</x-ui.auth-card>
