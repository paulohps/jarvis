/**
 * A WhatsApp-like emoji picker without a library: the emojis people use
 * most, by group, with the ones picked lately first (kept in this browser).
 * `x-data="emojiPicker()"` and, on a pick, the `emoji-picked` event with
 * `{ emoji }` (`$dispatch`), for the composer and the reactions.
 *
 * `emojiPicker.insertInto(textarea, emoji)` puts an emoji where the cursor
 * is and lets Livewire know (an `input` event).
 */
const RECENT_KEY = 'jarvis.emoji.recent';

const RECENT_LIMIT = 24;

export const EMOJI_GROUPS = [
    {
        key: 'smileys',
        icon: '😀',
        emojis: '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 😮‍💨 🤥 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🥵 🥶 🥴 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 🥹 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 💩 🤡 👻 👽 🤖 😺 😸 😹 😻 😼 😽 🙀 😿 😾',
    },
    {
        key: 'gestures',
        icon: '👍',
        emojis: '👍 👎 👌 🤌 🤏 ✌️ 🤞 🫰 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 🫡 👏 🙌 🫶 👐 🤲 🤝 🙏 ✍️ 💪 🦾 🧠 👀 👁️ 👅 👄 💋 🫂 👶 🧒 👦 👧 🧑 👨 👩 🧓 👴 👵 🙋 🙆 🙅 🤷 🤦 💁 🙇 🧑‍💻 👨‍👩‍👧 👫',
    },
    {
        key: 'hearts',
        icon: '❤️',
        emojis: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❤️‍🔥 ❤️‍🩹 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ♥️ 💯 💢 💥 💫 💦 💨 🕳️ 💬 🗨️ 💭 💤 ✨ ⭐ 🌟 🔥 🎉 🎊 🎈 🎁 🏆 🥇 ✅ ❌ ❗ ❓ ⚠️ 🚫 🔔 📌 📍',
    },
    {
        key: 'nature',
        icon: '🐶',
        emojis: '🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🐤 🦆 🦉 🐴 🦄 🐝 🦋 🐌 🐞 🐢 🐍 🐙 🐠 🐬 🐳 🦈 🐊 🐘 🦒 🐕 🐈 🌵 🌲 🌳 🌴 🌱 🌿 🍀 🍁 🍂 🌷 🌹 🌺 🌸 🌼 🌻 🌞 🌝 🌙 ⭐ ☀️ ⛅ 🌧️ ⛈️ ❄️ ☃️ 🌈 🌊',
    },
    {
        key: 'food',
        icon: '🍔',
        emojis: '🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🫐 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🥑 🥦 🥕 🌽 🌶️ 🥔 🍞 🧀 🥚 🍳 🥓 🥩 🍗 🍖 🌭 🍔 🍟 🍕 🥪 🌮 🌯 🥗 🍝 🍜 🍣 🍱 🍤 🍚 🍦 🍰 🎂 🧁 🍫 🍬 🍭 🍩 🍪 ☕ 🍵 🥤 🧃 🍺 🍻 🍷 🥂 🍾 🍹 🧉',
    },
    {
        key: 'activities',
        icon: '⚽',
        emojis: '⚽ 🏀 🏈 ⚾ 🎾 🏐 🏉 🎱 🏓 🏸 🥊 🥋 ⛳ 🎣 🏊 🏄 🚴 🏃 🧘 🏋️ 🤸 🎮 🎲 🧩 🎯 🎳 🎸 🎹 🥁 🎤 🎧 🎬 🎨 📚 ✈️ 🚗 🚕 🚌 🏍️ 🚲 🚀 ⛵ 🏠 🏡 🏢 🏥 🏫 ⛪ 🏖️ 🏝️ 🗺️ 🎡 🎢',
    },
    {
        key: 'objects',
        icon: '💡',
        emojis: '⌚ 📱 💻 ⌨️ 🖥️ 🖨️ 📷 📸 📹 🎥 📞 ☎️ 📺 📻 ⏰ ⏳ 🔋 🔌 💡 🔦 🕯️ 💸 💵 💰 💳 💎 🔧 🔨 🛠️ 🔑 🗝️ 🔒 🔓 🚪 🛏️ 🛋️ 🚿 🧹 🧺 🧻 🛒 🎒 👓 👔 👕 👖 👗 👟 👠 💍 💄 📦 ✉️ 📧 📝 📅 📆 📎 ✂️ 🗑️ 💊 💉 🩺',
    },
];

document.addEventListener('alpine:init', () => {
    window.Alpine.data('emojiPicker', () => ({
        group: 'recent',
        recent: [],
        groups: EMOJI_GROUPS.map((group) => ({ ...group, emojis: group.emojis.split(' ') })),

        init() {
            try {
                this.recent = JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]');
            } catch {
                this.recent = [];
            }

            if (this.recent.length === 0) {
                this.group = this.groups[0].key;
            }
        },

        get shown() {
            return this.group === 'recent' ? this.recent : (this.groups.find((group) => group.key === this.group)?.emojis ?? []);
        },

        pick(emoji) {
            this.recent = [emoji, ...this.recent.filter((used) => used !== emoji)].slice(0, RECENT_LIMIT);

            try {
                localStorage.setItem(RECENT_KEY, JSON.stringify(this.recent));
            } catch {
                // Private windows may refuse; the recent ones only last this page.
            }

            this.$dispatch('emoji-picked', { emoji });
        },
    }));
});

window.emojiPicker = {
    insertInto(textarea, emoji) {
        const start = textarea.selectionStart ?? textarea.value.length;
        const end = textarea.selectionEnd ?? textarea.value.length;

        textarea.value = textarea.value.slice(0, start) + emoji + textarea.value.slice(end);
        textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus();
    },
};
