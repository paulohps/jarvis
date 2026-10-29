/*
 * The service worker of the web app (US-12.2, the PWA), served by
 * ServiceWorkerController with the version of the current build.
 *
 * - Offline: the offline page and the icons are cached on install; a page
 *   that cannot be reached answers with the offline page, and the built
 *   assets (hashed names, so never stale) are kept once fetched. Livewire,
 *   the API and anything else go straight to the network.
 * - Push: a message from FCM (notification, data and the link of its first
 *   action) is shown as a notification, unless a window is already on that
 *   page; tapping it focuses a window of the app on the link, or opens one.
 * - A new version takes over as soon as it is installed.
 */
const VERSION = @json($version, JSON_UNESCAPED_SLASHES);
const CACHE = `jarvis-${VERSION}`;
const OFFLINE_URL = @json($offlineUrl, JSON_UNESCAPED_SLASHES);
const START_URL = @json($startUrl, JSON_UNESCAPED_SLASHES);
const ICON = @json($icon, JSON_UNESCAPED_SLASHES);
const BADGE = @json($badge, JSON_UNESCAPED_SLASHES);
const APP_NAME = @json($appName, JSON_UNESCAPED_SLASHES);
const PRECACHE = [OFFLINE_URL, '/icon-192.png', '/icon-512.png', '/badge-96.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL).then((cached) => cached ?? Response.error())),
        );

        return;
    }

    if (url.pathname.startsWith('/build/assets/') || PRECACHE.includes(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            })),
        );
    }
});

/**
 * The message as FCM delivers it to a browser: `notification` (title, body
 * and what `webpush.notification` added), `data` (`deep_link`, `thread`)
 * and the link of `webpush.fcm_options`, under one of a few names.
 */
function parsePush(event) {
    if (!event.data) {
        return null;
    }

    try {
        return event.data.json();
    } catch {
        return { notification: { title: APP_NAME, body: event.data.text() } };
    }
}

function linkOf(payload) {
    const link = payload.fcmOptions?.link
        ?? payload.fcm_options?.link
        ?? payload.notification?.click_action
        ?? payload.data?.deep_link
        ?? null;

    if (!link) {
        return START_URL;
    }

    try {
        const url = new URL(link, self.location.origin);

        return url.origin === self.location.origin ? url.pathname + url.search + url.hash : START_URL;
    } catch {
        return START_URL;
    }
}

async function windowsOfTheApp() {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    return clients.filter((client) => new URL(client.url).origin === self.location.origin);
}

self.addEventListener('push', (event) => {
    const payload = parsePush(event);

    if (!payload) {
        return;
    }

    const link = linkOf(payload);
    const notification = payload.notification ?? {};
    const title = notification.title ?? payload.data?.title ?? APP_NAME;
    const body = notification.body ?? payload.data?.body ?? '';
    const thread = notification.tag ?? payload.data?.thread ?? undefined;

    event.waitUntil((async () => {
        const windows = await windowsOfTheApp();

        // Tell the open pages (the bell refreshes, a chat reloads).
        windows.forEach((client) => client.postMessage({ type: 'push', link, title, body, data: payload.data ?? {} }));

        const alreadyThere = windows.some((client) => client.visibilityState === 'visible'
            && client.focused
            && new URL(client.url).pathname === link.split('?')[0]);

        if (alreadyThere) {
            return;
        }

        await self.registration.showNotification(title, {
            body,
            icon: notification.icon ?? ICON,
            badge: notification.badge ?? BADGE,
            image: notification.image,
            tag: thread,
            renotify: thread !== undefined,
            data: { link },
        });
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const link = event.notification.data?.link ?? START_URL;

    event.waitUntil((async () => {
        const windows = await windowsOfTheApp();
        const target = windows.find((client) => new URL(client.url).pathname === link.split('?')[0]) ?? windows[0];

        if (target) {
            await target.focus();

            if (new URL(target.url).pathname !== link.split('?')[0] && 'navigate' in target) {
                await target.navigate(link);
            }

            return;
        }

        await self.clients.openWindow(link);
    })());
});

// The push service replaced the subscription: the open pages register again.
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(windowsOfTheApp().then((windows) => windows.forEach((client) => client.postMessage({ type: 'resubscribe' }))));
});
