import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/*
 * Echo client for the realtime screens (WhatsApp connection status, shopping
 * lists and the in-app notification bell), served by Reverb.
 *
 * Loaded before Livewire boots so Livewire picks `window.Echo` up and wires the
 * components' `echo-private:` listeners itself. Without VITE_REVERB_APP_KEY
 * nothing is registered here and the screens keep working without realtime.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
