/**
 * The web app as an app (`$store.pwa`, US-12.2 and US-5.2 on the web):
 *
 * - registers the service worker (/sw.js) and keeps it current;
 * - offers the install: Chrome, Edge and the Android browsers raise
 *   `beforeinstallprompt`, kept here to prompt from the banner or the
 *   profile; on iPhone and iPad every browser (Safari, Chrome, Edge...)
 *   installs from its share sheet, so the banner shows the steps instead. The answer to the banner (remind
 *   me in N days, never) is the browser's, in localStorage;
 * - offers push the same way, in its own banner, only once the install
 *   banner is out of the way (answered, or not offered by this browser);
 * - turns push on and off: the FCM registration token of this browser
 *   (Firebase, loaded only then) is sent to the server as a `web` device,
 *   whose id is kept here so a refreshed token updates it. The browser
 *   re-sends the token when it changes and forgets the device when the
 *   profile revokes it.
 *
 * The page config (routes, the Firebase web app) comes from the JSON the
 * <x-pwa.head> component writes into <head>.
 */
const INSTALL_KEY = 'jarvis.pwa.install';
const PUSH_KEY = 'jarvis.pwa.push';
const PUSH_OFFER_KEY = 'jarvis.pwa.push-offer';
const DAY = 24 * 60 * 60 * 1000;

/**
 * How long after the page loads the install offer is taken as settled, so
 * the push banner never shows up just before `beforeinstallprompt` fires.
 */
const INSTALL_SETTLE_MS = 2500;

function readJson(key) {
    try {
        return JSON.parse(window.localStorage.getItem(key)) ?? {};
    } catch {
        return {};
    }
}

function writeJson(key, value) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage blocked: the choice lasts until the page reloads.
    }
}

function config() {
    try {
        return JSON.parse(document.getElementById('jarvis-pwa-config')?.textContent ?? '{}');
    } catch {
        return {};
    }
}

function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: minimal-ui)').matches
        || window.navigator.standalone === true;
}

function isAppleMobile() {
    const agent = window.navigator.userAgent;
    const isIpadOs = window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1;

    return /iPhone|iPad|iPod/.test(agent) || isIpadOs;
}

/**
 * The browser and the device, as the profile lists them ("Safari on iPhone").
 */
function browserName() {
    const agent = window.navigator.userAgent;
    const browser = /Edg\//.test(agent) ? 'Edge'
        : /OPR\//.test(agent) ? 'Opera'
            : /SamsungBrowser/.test(agent) ? 'Samsung Internet'
                : /Firefox|FxiOS/.test(agent) ? 'Firefox'
                    : /Chrome|CriOS/.test(agent) ? 'Chrome'
                        : /Safari/.test(agent) ? 'Safari'
                            : 'Browser';
    const device = /iPhone/.test(agent) ? 'iPhone'
        : /iPad/.test(agent) || (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1) ? 'iPad'
            : /Android/.test(agent) ? 'Android'
                : /Macintosh/.test(agent) ? 'Mac'
                    : /Windows/.test(agent) ? 'Windows'
                        : /Linux/.test(agent) ? 'Linux'
                            : null;

    return device ? `${browser} on ${device}` : browser;
}

document.addEventListener('alpine:init', () => {
    const settings = config();
    const installChoice = readJson(INSTALL_KEY);
    const pushState = readJson(PUSH_KEY);
    const pushOffer = readJson(PUSH_OFFER_KEY);

    window.Alpine.store('pwa', {
        isInstalled: isStandalone(),
        installPrompt: null,
        snoozedUntil: installChoice.until ?? null,
        neverOffer: installChoice.never === true,
        registration: null,

        push: {
            // unsupported | needs-install | denied | available | subscribed | busy | failed
            state: 'unsupported',
            deviceId: pushState.deviceId ?? null,
            token: pushState.token ?? null,
            snoozedUntil: pushOffer.until ?? null,
            neverOffer: pushOffer.never === true,
        },

        /**
         * Whether the install offer had its chance (`INSTALL_SETTLE_MS`
         * after the page loaded), so the push offer may follow it.
         */
        installSettled: false,

        /**
         * The browser installs on its own, from `beforeinstallprompt`.
         */
        get canPromptInstall() {
            return this.installPrompt !== null;
        },

        /**
         * iPhone and iPad: no browser there raises `beforeinstallprompt`;
         * all of them (Safari, Chrome, Edge, Firefox) install from the
         * share sheet since iOS 16.4, and the app gets push the same way
         * whichever browser added it.
         */
        get needsManualInstall() {
            return !this.isInstalled && this.installPrompt === null && isAppleMobile();
        },

        /**
         * Where the share button is on iPhone and iPad: Safari keeps it at
         * the bottom of the screen, the other browsers (Chrome, Edge,
         * Firefox) at the right of the address bar.
         */
        get sharesFromAddressBar() {
            return /CriOS|EdgiOS|FxiOS|OPiOS/.test(window.navigator.userAgent);
        },

        get shouldOfferInstall() {
            if (this.isInstalled || this.neverOffer || !settings.isAuthenticated) {
                return false;
            }

            if (this.snoozedUntil !== null && Date.now() < this.snoozedUntil) {
                return false;
            }

            return this.canPromptInstall || this.needsManualInstall;
        },

        /**
         * The push banner: never beside the install one — only once that
         * was answered (installed, later, never) or this browser offers no
         * install — and only while push can be turned on here.
         */
        get shouldOfferPush() {
            if (!settings.isAuthenticated || !this.installSettled || this.shouldOfferInstall) {
                return false;
            }

            if (this.push.neverOffer || this.push.state !== 'available') {
                return false;
            }

            return this.push.snoozedUntil === null || Date.now() >= this.push.snoozedUntil;
        },

        async init() {
            window.addEventListener('beforeinstallprompt', (event) => {
                event.preventDefault();
                this.installPrompt = event;
            });

            window.addEventListener('appinstalled', () => {
                this.isInstalled = true;
                this.installPrompt = null;
            });

            window.matchMedia('(display-mode: standalone)').addEventListener('change', () => {
                this.isInstalled = isStandalone();
            });

            document.addEventListener('livewire:init', () => {
                window.Livewire.on('pwa:device-revoked', ({ deviceId }) => this.forgetDevice(deviceId));
            });

            window.setTimeout(() => {
                this.installSettled = true;
            }, INSTALL_SETTLE_MS);

            await this.registerServiceWorker();
            await this.refreshPushState();
            await this.syncPushToken();
        },

        async registerServiceWorker() {
            if (!('serviceWorker' in navigator) || !settings.serviceWorkerUrl) {
                return;
            }

            try {
                this.registration = await navigator.serviceWorker.register(settings.serviceWorkerUrl, { scope: '/' });

                navigator.serviceWorker.addEventListener('message', (event) => this.onWorkerMessage(event.data));
            } catch (error) {
                console.warn('Service worker registration failed', error);
            }
        },

        onWorkerMessage(message) {
            if (message?.type === 'push') {
                window.Livewire?.dispatch('pwa:push-received');
            }

            if (message?.type === 'resubscribe') {
                this.syncPushToken(true);
            }
        },

        async install() {
            if (this.installPrompt === null) {
                return;
            }

            const prompt = this.installPrompt;
            this.installPrompt = null;

            prompt.prompt();

            const { outcome } = await prompt.userChoice;

            if (outcome === 'accepted') {
                this.isInstalled = true;
                writeJson(INSTALL_KEY, {});
            } else {
                this.installPrompt = prompt;
            }
        },

        snooze(days) {
            const count = Math.min(365, Math.max(1, Math.round(Number(days) || 1)));

            this.snoozedUntil = Date.now() + count * DAY;
            writeJson(INSTALL_KEY, { until: this.snoozedUntil });
        },

        never() {
            this.neverOffer = true;
            writeJson(INSTALL_KEY, { never: true });
        },

        /* Push */

        snoozePush(days) {
            const count = Math.min(365, Math.max(1, Math.round(Number(days) || 1)));

            this.push.snoozedUntil = Date.now() + count * DAY;
            writeJson(PUSH_OFFER_KEY, { until: this.push.snoozedUntil });
        },

        neverOfferPush() {
            this.push.neverOffer = true;
            writeJson(PUSH_OFFER_KEY, { never: true });
        },

        get pushIsSupported() {
            return settings.firebase !== null
                && settings.firebase !== undefined
                && 'serviceWorker' in navigator
                && 'PushManager' in window
                && 'Notification' in window;
        },

        async refreshPushState() {
            if (!this.pushIsSupported) {
                this.push.state = isAppleMobile() && !this.isInstalled && settings.firebase ? 'needs-install' : 'unsupported';

                return;
            }

            if (window.Notification.permission === 'denied') {
                this.push.state = 'denied';

                return;
            }

            this.push.state = this.push.deviceId !== null && window.Notification.permission === 'granted' ? 'subscribed' : 'available';
        },

        async messaging() {
            const [{ initializeApp, getApps }, messaging] = await Promise.all([
                import('firebase/app'),
                import('firebase/messaging'),
            ]);

            if (!(await messaging.isSupported())) {
                throw new Error('Firebase Messaging is not supported in this browser');
            }

            const { vapidKey, ...firebaseConfig } = settings.firebase;
            const app = getApps()[0] ?? initializeApp(firebaseConfig);

            return { messaging, instance: messaging.getMessaging(app), vapidKey };
        },

        async currentToken() {
            if (this.registration === null) {
                await this.registerServiceWorker();
            }

            await navigator.serviceWorker.ready;

            const { messaging, instance, vapidKey } = await this.messaging();

            return messaging.getToken(instance, { vapidKey, serviceWorkerRegistration: this.registration });
        },

        async subscribe() {
            if (!this.pushIsSupported) {
                return;
            }

            // Asked first, inside the tap: Safari only grants it from one.
            const permission = await window.Notification.requestPermission();

            if (permission !== 'granted') {
                this.push.state = permission === 'denied' ? 'denied' : 'available';

                return;
            }

            this.push.state = 'busy';

            try {
                await this.register(await this.currentToken());
                this.push.state = 'subscribed';
            } catch (error) {
                console.warn('Push registration failed', error);
                this.push.state = 'failed';
            }
        },

        async unsubscribe() {
            this.push.state = 'busy';

            const deviceId = this.push.deviceId;

            try {
                if (deviceId !== null) {
                    await fetch(settings.unsubscribeUrl.replace('__DEVICE__', deviceId), {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': settings.csrfToken, Accept: 'application/json' },
                        credentials: 'same-origin',
                    });
                }

                const { messaging, instance } = await this.messaging();
                await messaging.deleteToken(instance);
            } catch (error) {
                console.warn('Push removal failed', error);
            }

            this.forgetDevice(deviceId);
            this.push.state = 'available';
        },

        /**
         * The token of this browser goes to the server as a `web` device.
         */
        async register(token) {
            const response = await fetch(settings.subscribeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': settings.csrfToken, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ name: browserName(), push_token: token, device: this.push.deviceId }),
            });

            if (!response.ok) {
                throw new Error(`Push registration answered ${response.status}`);
            }

            const { data } = await response.json();

            this.push.deviceId = data.friendly_id;
            this.push.token = token;
            writeJson(PUSH_KEY, { deviceId: data.friendly_id, token, syncedAt: Date.now() });
        },

        /**
         * A browser that turned push on keeps the server current: the token
         * is read again on each visit (Firebase refreshes it) and sent only
         * when it changed, or when the push service asked to subscribe again.
         */
        async syncPushToken(force = false) {
            if (this.push.state !== 'subscribed' || !settings.isAuthenticated) {
                return;
            }

            try {
                const token = await this.currentToken();

                if (force || token !== this.push.token) {
                    await this.register(token);
                }
            } catch (error) {
                console.warn('Push token refresh failed', error);
            }
        },

        forgetDevice(deviceId) {
            if (deviceId !== null && deviceId !== this.push.deviceId) {
                return;
            }

            this.push.deviceId = null;
            this.push.token = null;
            writeJson(PUSH_KEY, {});

            if (this.push.state === 'subscribed') {
                this.push.state = 'available';
            }
        },
    });
});
