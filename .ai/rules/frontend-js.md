---
paths:
  - 'resources/js/**'
  - 'vite.config.js'
  - 'package.json'
  - 'public/**'
  - 'resources/brand/**'
  - 'resources/views/pwa/**'
  - 'resources/views/components/pwa/**'
  - 'app/Support/Pwa/**'
  - 'app/Http/Controllers/Pwa/**'
  - 'scripts/generate-brand-assets.py'
---

# Front-end JS, build and PWA

## Build tool is vite-plus
`npm run dev` / `npm run build` call `vp dev` / `vp build` (`vite-plus`, Vite 8), configured in `vite.config.js` with `defineConfig`/`lazyPlugins` from `vite-plus`. Entries are only `resources/css/app.css` and `resources/js/app.js`. `composer dev` (`php artisan dev`) runs server, queue, logs, Vite, plus `reverb:start` and `schedule:work` added in `AppServiceProvider::configureDevCommands()`.

## Remote dev server: VITE_DEV_ORIGIN
Set `VITE_DEV_ORIGIN=https://vite.example.dev` when the dev server sits behind a public domain: Vite binds 0.0.0.0, writes that origin to `public/hot` and runs HMR over `wss:443`. Unset, it is plain localhost. `VITE_PORT` is the Sail host port.

## Alpine comes from Livewire — never import it
`resources/js/app.js` only imports modules. Each module registers on `document.addEventListener('alpine:init', ...)` with `window.Alpine.store(...)` / `window.Alpine.data(...)`. Importing `alpinejs` would start a second Alpine. New component: add a file in `resources/js/components/` and an import line in `app.js`.

## Stores and components in use
Stores: `$store.sidebar` (collapse), `$store.pwa` (install/push), `$store.chatMenu` (one shared row menu), `$store.whatsappPresence`. Data: `templateEditor`, `whatsappEditor` (contenteditable composer, `wire:ignore`), `mediaPlayer`, `voiceRecorder`, `emojiPicker`. Reuse them before writing new ones.

## Browser-only preferences in localStorage, guarded
Per-browser choices use `jarvis.*` keys (`jarvis.sidebar`, `jarvis.pwa.install`, `jarvis.pwa.push`, `jarvis.pwa.push-offer`) and every access is in `try/catch` (storage can be blocked). Anything that must render before paint is mirrored by the inline script in `components/ui/document.blade.php` (sidebar), and re-applied on `livewire:navigated`.

## Server config reaches JS as JSON in <head>
Don't use `VITE_*` for runtime config that the server knows. `components/pwa/head.blade.php` writes `#jarvis-pwa-config` (routes, CSRF token, auth flag, Firebase web config) and `pwa.js` reads it. Strings shown by JS come from Blade via `@js(__('...'))` in the view, never hard-coded English in JS.

## Echo is optional
`resources/js/echo.js` creates `window.Echo` (Reverb) only when `VITE_REVERB_APP_KEY` is set at build time, before Livewire boots, so `echo-private:` listeners wire themselves. Code must not assume `window.Echo` exists. `VITE_REVERB_*` are baked into the bundle: rebuild after changing them.

## Service worker is a Blade view at /sw.js
`resources/views/pwa/service-worker.blade.php`, served by `ServiceWorkerController` from the root with `no-cache` and `Service-Worker-Allowed: /`. Its cache name is the hash of `public/build/manifest.json` (`WebManifest::version()`), so a deploy invalidates it. It caches only the offline page, icons and `/build/assets/*`; Livewire, API and pages always go to the network. Don't add a static `public/sw.js` or a `firebase-messaging-sw.js`.

## Manifest is dynamic and per user
`/manifest.webmanifest` (`WebManifestController`, `app/Support/Pwa/WebManifest.php`) is fetched with credentials, translated to the reader's language, and its shortcuts come from `AppNavigation` for that user. The offline page (`pwa/offline.blade.php`) loads no stylesheet: its colors come from `ThemeColors`.

## Web push via Firebase, only when configured
`pwa.js` lazy-imports `firebase/app` + `firebase/messaging` only when the user turns push on, uses the `/sw.js` registration for `getToken`, and posts the token to `push.subscriptions.store` as a `web` device. Without all `FIREBASE_WEB_*` vars + `FIREBASE_PROJECT_ID`, `WebPush::clientConfig()` is null and no push UI renders. `phpunit.xml` pins these empty — tests must not depend on a developer's `.env`.

## iOS install quirks
No iOS browser fires `beforeinstallprompt`; Safari, Chrome, Edge and Firefox on iPhone/iPad install from the share sheet (iOS 16.4+), and push there only works once installed (`needs-install` state). Safari grants notification permission only from a user tap, so `subscribe()` asks first inside the click. Keep these branches when touching `pwa.js`.

## Brand assets are generated
Icons (`public/icon-*.png`, `apple-touch-icon.png`, `badge-96.png`, favicons), iOS splash screens (`public/splash/*`) and the native app icons come from `resources/brand/*.svg` via `python3 scripts/generate-brand-assets.py` (needs Pillow and Google Chrome; the Chrome path is hard-coded for macOS). Its `SCREENS` list must match `app/Support/Pwa/StartupImages.php`; `PwaTest` checks every listed splash exists at its size.

## Classes built in JS must be scannable
Tailwind scans `resources/js` (`@source '../js'` in `app.css`), so write full class names in JS (`'bg-tint-fill'`), never concatenated fragments.
