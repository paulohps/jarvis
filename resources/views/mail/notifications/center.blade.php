<x-mail::message>
<h1>{{ $title }}</h1>

{!! $body !!}

@foreach ($buttons as $button)
<x-mail::button :url="$button['url']">
{{ $button['label'] }}
</x-mail::button>
@endforeach

@if ($preferencesUrl !== null)
<x-mail::subcopy>
{{ __('You get this e-mail because of your notification preferences.') }} [{{ __('Manage notifications') }}]({{ $preferencesUrl }})
</x-mail::subcopy>
@endif
</x-mail::message>
