/**
 * The one menu of the WhatsApp screen, shared by every chat of the list and
 * every message, so that the page does not carry a menu per row: a button
 * opens it with `$store.chatMenu.show(kind, $el)` (`chat`, `message` or
 * `react`), and the menu reads what it offers from the button's `data-*`
 * attributes (`$store.chatMenu.data`). It sits next to the button, below
 * it, or above when there is no room, and closes on a click elsewhere,
 * Escape, a scroll or a resize.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.store('chatMenu', {
        kind: null,
        data: {},
        // An object, so that Alpine merges it with what x-show sets.
        style: {},
        anchor: null,
        // The reaction on its way to WhatsApp, by message, shown at once.
        pending: {},

        react(wire, emoji) {
            const id = this.data.id;
            this.pending[id] = emoji;
            this.close();
            wire.react(id, emoji).finally(() => delete this.pending[id]);
        },

        show(kind, anchor) {
            if (this.kind === kind && this.anchor === anchor) {
                this.close();

                return;
            }

            this.kind = kind;
            this.anchor = anchor;
            this.data = { ...anchor.dataset };
            this.style = { visibility: 'hidden', top: '0px', left: '0px' };

            requestAnimationFrame(() => this.place());
        },

        place() {
            const menu = document.querySelector(`[data-chat-shared-menu="${this.kind}"]`);

            if (! menu || ! this.anchor) {
                return;
            }

            const rect = this.anchor.getBoundingClientRect();
            const width = menu.offsetWidth;
            const height = menu.offsetHeight;
            const gap = 4;
            const below = rect.bottom + gap + height <= window.innerHeight - 8;
            const top = below ? rect.bottom + gap : Math.max(8, rect.top - gap - height);
            const alignEnd = this.data.align !== 'start';
            const left = Math.min(
                Math.max(8, alignEnd ? rect.right - width : rect.left),
                window.innerWidth - width - 8,
            );

            this.style = { visibility: 'visible', top: `${top}px`, left: `${left}px` };
        },

        close() {
            this.kind = null;
            this.anchor = null;
            this.data = {};
        },

        is(kind) {
            return this.kind === kind;
        },

        flag(name) {
            return this.data[name] === '1';
        },
    });

    window.addEventListener('resize', () => window.Alpine.store('chatMenu').close());
    window.addEventListener('scroll', (event) => {
        if (! (event.target instanceof Element && event.target.closest('[data-chat-shared-menu]'))) {
            window.Alpine.store('chatMenu').close();
        }
    }, true);
});
