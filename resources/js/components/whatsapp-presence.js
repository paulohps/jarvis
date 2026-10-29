/**
 * Who is typing, recording an audio or online in the WhatsApp chats, as the
 * `whatsapp.chat.presence` broadcasts tell (WhatsappChatPresenceChanged):
 * `Alpine.store('whatsappPresence')`, started once by the chat screen with
 * the user's private channel and the labels in the user's language, and
 * read by the chat header and list with `text(chatId)`.
 *
 * Typing and recording fade after ACTIVITY_MS without news (WhatsApp keeps
 * repeating them while they go on), online after ONLINE_MS. Nothing is
 * kept across page loads.
 */
const ACTIVITY_MS = 10_000;

const ONLINE_MS = 5 * 60_000;

document.addEventListener('alpine:init', () => {
    window.Alpine.store('whatsappPresence', {
        chats: {},
        labels: {},
        channel: null,
        now: Date.now(),

        start(channel, labels) {
            this.labels = labels;

            if (this.channel === channel || !window.Echo) {
                return;
            }

            this.channel = channel;
            window.Echo.private(channel).listen('.whatsapp.chat.presence', (event) => this.receive(event));
            setInterval(() => { this.now = Date.now(); }, 2_000);
        },

        receive(event) {
            const chat = { ...(this.chats[event.chat_id] ?? {}) };

            if (event.last_seen) {
                chat.lastSeen = event.last_seen;
            }

            chat.presence = event.presence;
            chat.who = event.who;
            chat.at = Date.now();

            this.chats = { ...this.chats, [event.chat_id]: chat };
        },

        text(chatId) {
            const chat = this.chats[chatId];

            if (!chat) {
                return '';
            }

            const age = this.now - chat.at;
            const fill = (label) => (label ?? '').replace(':who', chat.who ?? '');

            if (chat.presence === 'composing' && age < ACTIVITY_MS) {
                return fill(chat.who ? this.labels.typingWho : this.labels.typing);
            }

            if (chat.presence === 'recording' && age < ACTIVITY_MS) {
                return fill(chat.who ? this.labels.recordingWho : this.labels.recording);
            }

            if (!chat.who && ['available', 'composing', 'recording', 'paused'].includes(chat.presence) && age < ONLINE_MS) {
                return this.labels.online;
            }

            if (!chat.who && chat.lastSeen) {
                const seen = new Date(chat.lastSeen);

                return (this.labels.lastSeen ?? '').replace(':time', seen.toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'short', timeStyle: 'short' }));
            }

            return '';
        },

        active(chatId) {
            const presence = this.chats[chatId]?.presence;

            return ['composing', 'recording'].includes(presence) && this.now - this.chats[chatId].at < ACTIVITY_MS;
        },
    });
});
