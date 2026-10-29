{{--
    What the service worker shows when a page cannot be reached (US-12.2,
    the PWA). Self-contained: no stylesheet or script to fetch, since
    there is no connection; the colors are the tokens of the theme,
    written out in App\Support\ThemeColors.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light dark">
        <title>{{ __('You are offline') }} · {{ config('app.name') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <style>
            :root { color-scheme: light dark; --background: {{ \App\Support\ThemeColors::GROUPED_BACKGROUND_LIGHT }}; --label: {{ \App\Support\ThemeColors::LABEL_LIGHT }}; --secondary: {{ \App\Support\ThemeColors::SECONDARY_LABEL_LIGHT }}; --tint: {{ \App\Support\ThemeColors::TINT_FILL_LIGHT }}; --on-tint: {{ \App\Support\ThemeColors::ON_TINT }}; }
            @media (prefers-color-scheme: dark) { :root { --background: {{ \App\Support\ThemeColors::GROUPED_BACKGROUND_DARK }}; --label: {{ \App\Support\ThemeColors::LABEL_DARK }}; --secondary: {{ \App\Support\ThemeColors::SECONDARY_LABEL_DARK }}; --tint: {{ \App\Support\ThemeColors::TINT_FILL_DARK }}; } }
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: calc(1.5rem + env(safe-area-inset-top)) 1.5rem calc(1.5rem + env(safe-area-inset-bottom)); background: var(--background); color: var(--label); font-family: -apple-system, BlinkMacSystemFont, 'SF Pro', system-ui, sans-serif; -webkit-font-smoothing: antialiased; text-align: center; }
            main { max-width: 24rem; display: flex; flex-direction: column; align-items: center; gap: 1rem; }
            img { width: 5rem; height: 5rem; border-radius: 1.25rem; }
            h1 { margin: 0; font-size: 1.5rem; letter-spacing: -0.01em; }
            p { margin: 0; color: var(--secondary); font-size: 0.9375rem; line-height: 1.4; }
            button { margin-top: 0.5rem; border: 0; border-radius: 0.75rem; background: var(--tint); color: var(--on-tint); font: inherit; font-weight: 600; padding: 0.75rem 1.5rem; cursor: pointer; }
        </style>
    </head>
    <body>
        <main>
            <img src="/icon-192.png" alt="" width="80" height="80">
            <h1>{{ __('You are offline') }}</h1>
            <p>{{ __(':app needs a connection to show this screen. It opens again as soon as you are back online.', ['app' => config('app.name')]) }}</p>
            <button type="button" onclick="location.reload()">{{ __('Try again') }}</button>
        </main>
    </body>
</html>
