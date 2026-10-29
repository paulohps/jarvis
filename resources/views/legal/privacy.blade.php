{{-- The privacy policy, public so Google can show it on the OAuth consent screen. --}}
<x-ui.legal-page :title="__('Privacy policy')" updated-at="2026-10-05">
    <p>{{ __('Jarvis is a private household assistant. Accounts only exist by invitation, and it is not offered to the public.') }}</p>

    <section class="flex flex-col gap-2">
        <h2>{{ __('What Jarvis stores') }}</h2>
        <ul>
            <li>{{ __('Your name, e-mail address, language, timezone and, when you add one, your photo.') }}</li>
            <li>{{ __('What you register in the modules you use, such as invoices, payers, reminders and shopping lists.') }}</li>
            <li>{{ __('For each mail service you connect, the address it sends from and its credentials, encrypted.') }}</li>
        </ul>
    </section>

    <section class="flex flex-col gap-2">
        <h2>{{ __('Google account data') }}</h2>
        <ul>
            <li>{{ __('Signing in with Google reads only your basic profile (name, e-mail address and photo), to identify your account.') }}</li>
            <li>{{ __('Connecting Gmail as a mail service asks only to send e-mail on your behalf (gmail.send). Jarvis keeps the authorization Google returns, encrypted, and uses it only to send the messages you set up, such as invoices. It never reads, lists or deletes your e-mail.') }}</li>
            <li>{{ __("Jarvis's use and transfer of information received from Google APIs adheres to the Google API Services User Data Policy, including the Limited Use requirements.") }}</li>
        </ul>
    </section>

    <section class="flex flex-col gap-2">
        <h2>{{ __('Sharing') }}</h2>
        <p>{{ __('Your data is not sold, shared with third parties or used for advertising. It only leaves Jarvis when a feature you set up sends it, such as an invoice e-mailed to its recipients.') }}</p>
    </section>

    <section class="flex flex-col gap-2">
        <h2>{{ __('Removing your data') }}</h2>
        <p>{{ __('You can disconnect a mail service at any time in Jarvis, or revoke its access in your Google account settings. To delete your account and its data, write to :email.', ['email' => config('app.contact_email')]) }}</p>
    </section>
</x-ui.legal-page>
