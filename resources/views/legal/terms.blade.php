{{-- The terms of use, public so Google can show them on the OAuth consent screen. --}}
<x-ui.legal-page :title="__('Terms of use')" updated-at="2026-10-05">
    <p>{{ __('Jarvis is a private household assistant. Accounts only exist by invitation, and it is not offered to the public.') }}</p>

    <section class="flex flex-col gap-2">
        <h2>{{ __('Use') }}</h2>
        <ul>
            <li>{{ __('Use Jarvis only for your own household tasks, and keep your sign-in to yourself.') }}</li>
            <li>{{ __('You are responsible for what you send through it, such as invoices and messages, and for having the right to send them.') }}</li>
        </ul>
    </section>

    <section class="flex flex-col gap-2">
        <h2>{{ __('Availability') }}</h2>
        <p>{{ __('Jarvis is provided as is, without guarantees of availability. An account may be suspended when these terms are not followed.') }}</p>
    </section>

    <section class="flex flex-col gap-2">
        <h2>{{ __('Contact') }}</h2>
        <p>{{ __('Questions about these terms or the privacy policy: :email.', ['email' => config('app.contact_email')]) }}</p>
    </section>
</x-ui.legal-page>
