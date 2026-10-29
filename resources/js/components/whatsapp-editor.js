/**
 * The text field of the WhatsApp screen, as on WhatsApp Web: it grows with
 * the text, a selection opens a balloon that formats it the WhatsApp way
 * (*bold*, _italic_, ~strikethrough~, ```monospace```, `code`, lists and
 * quotes), and in a group `@` lists the members, a picked one showing as
 * their name while the text keeps `@` and their number, which is what
 * WhatsApp mentions.
 *
 *   x-data="whatsappEditor({ model: 'text', group: true, send: 'send' })"
 *
 * `model` is the Livewire property the text goes to (deferred, sent with
 * the next action), or null for a field that only holds `initial` (the
 * caller reads `value`); `group` turns the mentions on (the members come
 * from the Livewire `mentionables`, loaded by `loadMentionables()` the
 * first time they are needed), and `send`, when given, is what Enter does:
 * the name of a Livewire action, or a function given the editor
 * (Shift+Enter, and Enter on a touch screen, break the line). The field is
 * the element with `x-ref="field"`, kept out of Livewire's morphing
 * (wire:ignore); `empty` says whether it holds anything. `typing` tells
 * the Livewire `typing()` every few seconds while the user types.
 */
const FORMATS = {
    bold: { wrap: '*' },
    italic: { wrap: '_' },
    strike: { wrap: '~' },
    mono: { wrap: '```' },
    code: { wrap: '`' },
    bullets: { line: () => '- ' },
    numbers: { line: (index) => `${index + 1}. ` },
    quote: { line: () => '> ' },
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('whatsappEditor', ({ model = null, initial = null, group = false, send = null, typing = false }) => ({
        empty: true,
        value: '',
        mentioning: false,
        query: '',
        requested: false,
        balloon: null,
        typingAt: 0,

        init() {
            this.value = model !== null ? (this.$wire.$get(model) ?? '') : (initial ?? '');
            this.render(this.value);

            if (model !== null) {
                this.$wire.$watch(model, (value) => {
                    if ((value ?? '') !== this.value) {
                        this.value = value ?? '';
                        this.render(this.value);
                    }
                });
            }

            if (group) {
                this.$wire.$watch('mentionables', () => this.render(this.value, true));
            }

            this.onSelection = () => this.updateBalloon();
            document.addEventListener('selectionchange', this.onSelection);
        },

        destroy() {
            document.removeEventListener('selectionchange', this.onSelection);
        },

        field() {
            return this.$refs.field;
        },

        members() {
            return group ? (this.$wire.mentionables ?? []) : [];
        },

        nameOf(number) {
            return this.members().find((member) => member.number === number)?.name ?? null;
        },

        /** The text of the field: a mention as `@` and the number, a line break as `\n`. */
        serialize(node = this.field()) {
            let text = '';

            node.childNodes.forEach((child, index) => {
                if (child.nodeType === Node.TEXT_NODE) {
                    text += child.nodeValue.replace(/ /g, ' ');
                } else if (child.dataset?.mention) {
                    text += '@' + child.dataset.mention;
                } else if (child.nodeName === 'BR') {
                    text += '\n';
                } else if (child.nodeName === 'DIV' || child.nodeName === 'P') {
                    text += (index > 0 ? '\n' : '') + this.serialize(child);
                } else {
                    text += this.serialize(child);
                }
            });

            return text;
        },

        chip(number, name) {
            const chip = document.createElement('span');
            chip.contentEditable = 'false';
            chip.dataset.mention = number;
            chip.className = 'rounded px-0.5 font-medium text-chat-link';
            chip.textContent = '@' + name;

            return chip;
        },

        /** Fills the field with a text, the mentions it can name as chips. */
        render(text, keepCaret = false) {
            const field = this.field();

            if (keepCaret && field.contains(document.activeElement) && ! /@\d{8,15}/.test(text)) {
                return;
            }

            const fragment = document.createDocumentFragment();
            const pattern = /@(\d{8,15})(?!\d)/g;

            text.split('\n').forEach((line, index) => {
                if (index > 0) {
                    fragment.appendChild(document.createElement('br'));
                }

                let last = 0;

                for (const match of line.matchAll(pattern)) {
                    const name = this.nameOf(match[1]);

                    if (name === null) {
                        continue;
                    }

                    fragment.appendChild(document.createTextNode(line.slice(last, match.index)));
                    fragment.appendChild(this.chip(match[1], name));
                    last = match.index + match[0].length;
                }

                fragment.appendChild(document.createTextNode(line.slice(last)));
            });

            field.replaceChildren(fragment);
            this.empty = text.trim() === '';

            if (group && ! this.requested && /@\d{8,15}/.test(text)) {
                this.requested = true;
                this.$wire.loadMentionables();
            }
        },

        sync() {
            this.value = this.serialize();
            this.empty = this.value.trim() === '';

            if (model !== null) {
                this.$wire.$set(model, this.value, false);
            }
        },

        onInput() {
            this.sync();
            this.track();

            if (typing && ! this.empty && Date.now() - this.typingAt > 4000) {
                this.typingAt = Date.now();
                this.$wire.typing();
            }
        },

        onKeydown(event) {
            if (event.key === 'Escape' && this.mentioning) {
                event.stopPropagation();
                this.mentioning = false;

                return;
            }

            if (event.key !== 'Enter') {
                return;
            }

            if (this.mentioning && this.filtered().length > 0) {
                event.preventDefault();
                this.pick(this.filtered()[0]);

                return;
            }

            const touch = window.matchMedia('(pointer: coarse)').matches;

            if (event.shiftKey || send === null || touch) {
                event.preventDefault();
                this.insert('\n');

                return;
            }

            event.preventDefault();
            this.submit();
        },

        submit() {
            this.sync();

            if (typeof send === 'function') {
                send(this);
            } else {
                this.$wire.$call(send);
            }
        },

        onPaste(event) {
            event.preventDefault();
            this.insert(event.clipboardData.getData('text/plain'));
        },

        /** Types a text where the caret is (an emoji, a pasted text, a line break). */
        insert(text) {
            const field = this.field();

            if (! field.contains(document.getSelection().anchorNode)) {
                this.caretToEnd();
            }

            if (text === '\n') {
                const selection = document.getSelection();
                const range = selection.getRangeAt(0);
                range.deleteContents();
                const br = document.createElement('br');
                range.insertNode(br);
                // A trailing <br> needs a second one to show the new line.
                if (! br.nextSibling || (br.nextSibling.nodeType === Node.TEXT_NODE && br.nextSibling.nodeValue === '' && ! br.nextSibling.nextSibling)) {
                    br.after(document.createElement('br'));
                }
                range.setStartAfter(br);
                range.collapse(true);
                selection.removeAllRanges();
                selection.addRange(range);
            } else {
                document.execCommand('insertText', false, text);
            }

            this.onInput();
        },

        caretToEnd() {
            const field = this.field();
            field.focus();
            const range = document.createRange();
            range.selectNodeContents(field);
            range.collapse(false);
            document.getSelection().removeAllRanges();
            document.getSelection().addRange(range);
        },

        clear() {
            this.value = '';
            this.render('');

            if (model !== null) {
                this.$wire.$set(model, '', false);
            }
        },

        /* Mentions */

        filtered() {
            const query = this.query.toLowerCase();

            return this.members().filter((member) => member.name.toLowerCase().includes(query) || member.number.includes(query)).slice(0, 8);
        },

        /** Whether the caret follows `@` and what was typed after it. */
        track() {
            if (! group) {
                return;
            }

            const selection = document.getSelection();
            const node = selection.anchorNode;

            if (! selection.isCollapsed || node?.nodeType !== Node.TEXT_NODE) {
                this.mentioning = false;

                return;
            }

            const match = node.nodeValue.slice(0, selection.anchorOffset).match(/(^|\s)@([^\s@]*)$/);
            this.mentioning = match !== null;
            this.query = match ? match[2] : '';

            if (this.mentioning && ! this.requested) {
                this.requested = true;
                this.$wire.loadMentionables();
            }
        },

        pick(member) {
            const selection = document.getSelection();
            const node = selection.anchorNode;

            if (node?.nodeType !== Node.TEXT_NODE) {
                return;
            }

            const offset = selection.anchorOffset;
            const before = node.nodeValue.slice(0, offset);
            const start = before.lastIndexOf('@');
            const after = node.splitText(offset);
            node.nodeValue = before.slice(0, start);

            const chip = this.chip(member.number, member.name);
            const space = document.createTextNode(' ');
            after.before(chip, space);

            const range = document.createRange();
            range.setStart(space, 1);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);

            this.mentioning = false;
            this.sync();
            this.field().focus();
        },

        /* The formatting balloon */

        updateBalloon() {
            const selection = document.getSelection();

            if (! selection.rangeCount || selection.isCollapsed || ! this.field().contains(selection.anchorNode) || ! this.field().contains(selection.focusNode)) {
                this.balloon = null;

                return;
            }

            const rect = selection.getRangeAt(0).getBoundingClientRect();
            this.balloon = {
                top: `${Math.max(8, rect.top - 52)}px`,
                left: `${Math.min(Math.max(8, rect.left + rect.width / 2 - 160), window.innerWidth - 328)}px`,
            };
        },

        format(name) {
            const selection = document.getSelection();

            if (! selection.rangeCount || selection.isCollapsed) {
                return;
            }

            const range = selection.getRangeAt(0);
            const holder = document.createElement('div');
            holder.appendChild(range.cloneContents());
            const text = this.serialize(holder);
            const format = FORMATS[name];

            const formatted = format.wrap
                ? text.replace(/^(\s*)([\s\S]*?)(\s*)$/, (all, lead, body, trail) => `${lead}${format.wrap}${body}${format.wrap}${trail}`)
                : text.split('\n').map((line, index) => format.line(index) + line).join('\n');

            range.deleteContents();
            const lines = formatted.split('\n');
            const fragment = document.createDocumentFragment();
            lines.forEach((line, index) => {
                if (index > 0) {
                    fragment.appendChild(document.createElement('br'));
                }
                fragment.appendChild(document.createTextNode(line));
            });
            const last = fragment.lastChild;
            range.insertNode(fragment);
            range.setStartAfter(last);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);

            this.balloon = null;
            this.render(this.serialize());
            this.sync();
            this.caretToEnd();
        },
    }));
});
