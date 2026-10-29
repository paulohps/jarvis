@if (($exception ?? null) instanceof \App\Exceptions\ModuleAccessDenied)
    <x-ui.error-page code="403" :title="__('You don\'t have access to this module')" :message="__('Ask the admin to enable this module for you.')" />
@else
    <x-ui.error-page code="403" :title="__('Access denied')" :message="__('You do not have permission to open this page.')" />
@endif
