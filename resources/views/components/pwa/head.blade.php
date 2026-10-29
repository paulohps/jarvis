{{--
    What makes the page installable as an app (US-12.2, the PWA): the
    manifest (fetched with the session, so it comes in the reader's
    language), the home screen metadata iOS reads from the page instead,
    its launch screens, and the config the page script needs — the routes
    of the service worker and the push registration, and the Firebase web
    app when push is set up. Rendered inside <head> by <x-ui.document>.
--}}
<link rel="manifest" href="{{ route('pwa.manifest') }}" crossorigin="use-credentials">
<meta name="application-name" content="{{ config('app.name') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="format-detection" content="telephone=no">
@foreach (\App\Support\Pwa\StartupImages::links() as $image)
    <link rel="apple-touch-startup-image" href="{{ $image['href'] }}" media="{{ $image['media'] }}">
@endforeach
@php
    $pwaConfig = [
        'serviceWorkerUrl' => route('pwa.service-worker', absolute: false),
        'isAuthenticated' => auth()->check(),
        'csrfToken' => csrf_token(),
        'subscribeUrl' => route('push.subscriptions.store', absolute: false),
        'unsubscribeUrl' => route('push.subscriptions.destroy', ['device' => '__DEVICE__'], absolute: false),
        'firebase' => \App\Support\Pwa\WebPush::clientConfig(),
    ];
@endphp
<script type="application/json" id="jarvis-pwa-config">{!! json_encode($pwaConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
