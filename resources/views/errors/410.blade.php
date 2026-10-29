@if (($exception ?? null) instanceof \App\Exceptions\InvalidInvitation)
    <x-ui.error-page code="410" :title="__('This invitation is no longer valid')" :message="__('The link expired, was revoked or was already used. Ask the admin for a new invitation.')" />
@else
    <x-ui.error-page code="410" :title="__('Page no longer available')" :message="__('What was here has been removed.')" />
@endif
