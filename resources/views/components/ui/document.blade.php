<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light dark">

        <title>{{ $documentTitle() }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <x-pwa.head />
        <meta name="theme-color" content="{{ \App\Support\ThemeColors::GROUPED_BACKGROUND_LIGHT }}" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="{{ \App\Support\ThemeColors::GROUPED_BACKGROUND_DARK }}" media="(prefers-color-scheme: dark)">

        {{-- The sidebar collapsed in this browser, before the first paint (resources/js/components/sidebar.js). --}}
        <script>try { if (localStorage.getItem('jarvis.sidebar') === 'collapsed') document.documentElement.dataset.sidebar = 'collapsed'; } catch {}</script>

        <tallstackui:script />
        @livewireStyles
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body {{ $attributes->class('min-h-dvh overflow-x-clip bg-grouped-background font-sans text-label antialiased') }}>
        <x-toast />
        <x-dialog />

        {{ $slot }}

        @livewireScripts
    </body>
</html>
