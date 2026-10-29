import {
    appendStreamText,
    closingTag,
    openingTag,
    parseStream,
    parseTemplate,
    serializeBlocks,
    serializeStream,
    UnsupportedTemplate,
} from './codec';

/**
 * Visual editor for `{% %}` templates (ported from my-finance), rendered by the
 * <x-ui.template-editor> Blade component and bound through x-modelable, so `wire:model` works
 * on it as on any input. Its `mode` follows how the server renders the template:
 *
 * - `rich`: an HTML body — bold, italic, underline, lists, variables as chips, and "show only
 *   if" / "repeat for each" blocks around whole paragraphs;
 * - `plain`: a plain-text body (a WhatsApp message) — text kept exactly as written, line
 *   breaks included, with variables and each condition or repeat tag as chips in the text;
 * - `inline`: the same on a single line (a title, an e-mail subject).
 *
 * Whatever cannot be shown faithfully opens as code ("Edit as code"), with the reason on
 * screen. Every word it writes on screen comes translated from the server (`config.labels`).
 *
 * There is no editor library behind it, by choice: formatting goes through
 * document.execCommand — deprecated, still implemented by every browser — and the codec keeps
 * whatever the browser produces inside what the server allows.
 */

const SYNC_DELAY_MS = 400;

// Inline and without a weight or decoration of its own, so a variable inside bold, italic or
// underlined text looks the part (an inline-block would not carry the underline).
const CHIP_CLASS = 'mx-0.5 px-1 py-px rounded border font-mono text-[0.85em] whitespace-nowrap select-none cursor-pointer';
const CHIP_KNOWN_CLASS = 'bg-tint-fill/10 border-tint/30 text-tint';
const CHIP_UNKNOWN_CLASS = 'bg-danger/10 border-danger/40 text-danger';
const CHIP_SELECTED_CLASSES = ['ring-2', 'ring-tint'];

/** A condition or repeat tag in a plain-text template. */
const TAG_CHIP_CLASS = {
    if: 'bg-warning/10 border-warning/40 text-warning',
    else: 'bg-warning/10 border-warning/40 text-warning',
    endif: 'bg-warning/10 border-warning/40 text-warning',
    for: 'bg-success/10 border-success/40 text-success',
    endfor: 'bg-success/10 border-success/40 text-success',
};

const BLOCK_CLASS = {
    if: 'my-2 rounded-lg border-l-4 border-warning bg-warning/5',
    for: 'my-2 rounded-lg border-l-4 border-success bg-success/5',
};

const FORMAT_TAGS = { bold: 'strong', italic: 'em', underline: 'u' };
const FORMAT_SELECTORS = { strong: 'strong, b', em: 'em, i', u: 'u' };
const FORMAT_SHORTCUTS = { b: 'bold', i: 'italic', u: 'underline' };

/** Formatting never reaches past these: a paragraph, a list item, a block. */
const BOUNDARY_TAGS = ['P', 'LI', 'UL', 'OL', 'DIV'];

document.addEventListener('alpine:init', () => {
    window.Alpine.data('templateEditor', (config) => {
        // Kept out of Alpine's reactive state: a Range or an element wrapped in a proxy is
        // refused by the DOM APIs, and none of these drive the markup.
        let savedRange = null;
        let syncTimer = null;
        let onSelectionChange = null;
        let editingBlock = null;
        // Whether something was typed or inserted since the last sync: a template only
        // loaded is never written back, so opening a screen changes nothing.
        let edited = false;

        const isRich = config.mode === 'rich';
        const isInline = config.mode === 'inline';

        return {
            config,
            value: config.value ?? '',
            mode: 'visual',
            unsupported: null,
            menu: null,
            // The condition being set up in the Condition menu: { path, label, operator,
            // value, values, editing }. operator '' only checks that the variable has a value.
            condition: null,
            scope: [],
            isEmpty: true,
            lastSerialized: null,

            init() {
                this.load(this.value);

                // A value that did not come from typing here (the first one from Livewire,
                // or one the server changed) is loaded again.
                this.$watch('value', (value) => {
                    if (this.mode === 'visual' && value !== this.lastSerialized) {
                        this.load(value);
                    }
                });

                onSelectionChange = () => this.rememberSelection();
                document.addEventListener('selectionchange', onSelectionChange);
            },

            destroy() {
                document.removeEventListener('selectionchange', onSelectionChange);
                clearTimeout(syncTimer);
            },

            load(template) {
                const surface = this.$refs.surface;

                clearTimeout(syncTimer);
                edited = false;

                try {
                    surface.replaceChildren(isRich
                        ? parseTemplate(template ?? '', this.builders())
                        : parseStream(template ?? '', this.builders()));
                    this.ensureEditableEdges();
                    this.lastSerialized = template ?? '';
                    this.unsupported = null;
                    this.mode = 'visual';
                } catch (error) {
                    if (! (error instanceof UnsupportedTemplate)) {
                        throw error;
                    }

                    surface.replaceChildren(...(isRich ? [this.paragraph()] : []));
                    this.unsupported = this.describeUnsupported(error);
                    this.mode = 'code';
                }

                savedRange = null;
                this.scope = [];
                this.refreshEmpty();
            },

            /** A translated word or sentence of the editor, by key. */
            label(key) {
                return this.config.labels?.[key] ?? key;
            },

            /** Why the visual editor cannot show a template, in the reader's language. */
            describeUnsupported(error) {
                const reason = this.config.labels?.unsupported?.[error.code] ?? error.message;

                return reason.replace(':tag', error.detail ?? '');
            },

            toggleMode() {
                this.closeMenu();

                if (this.mode === 'visual') {
                    this.sync();
                    this.mode = 'code';

                    return;
                }

                // Stays in code, with the reason on screen, when the template cannot be shown.
                this.load(this.value);
            },

            toggleMenu(name) {
                const isOpen = this.menu === name;

                this.closeMenu();

                if (! isOpen) {
                    this.menu = name;
                }
            },

            closeMenu() {
                this.menu = null;
                this.condition = null;
                editingBlock = null;
            },

            scheduleSync() {
                edited = true;
                clearTimeout(syncTimer);
                syncTimer = setTimeout(() => this.sync(), SYNC_DELAY_MS);
                this.refreshEmpty();
            },

            sync() {
                clearTimeout(syncTimer);

                if (this.mode !== 'visual' || ! edited) {
                    return;
                }

                const surface = this.$refs.surface;

                if (isRich) {
                    this.repairBlocks();
                }

                const template = isRich ? serializeBlocks(surface) : serializeStream(surface);

                edited = false;
                this.lastSerialized = template;

                if (template !== this.value) {
                    this.value = template;
                }
            },

            refreshEmpty() {
                const surface = this.$refs.surface;

                this.isEmpty = surface.textContent.trim() === ''
                    && surface.querySelector('[data-tpl-var], [data-tpl-block], [data-tpl-tag], li') === null;
            },

            onFocus() {
                if (isRich) {
                    document.execCommand('defaultParagraphSeparator', false, 'p');
                }

                this.$dispatch('template-editor-focus', { name: this.config.name });
            },

            onPaste(event) {
                // Only text: formatting copied from elsewhere would bring markup the server drops.
                const text = event.clipboardData?.getData('text/plain') ?? '';

                if (isRich) {
                    document.execCommand('insertText', false, text);
                } else {
                    this.insertStreamText(isInline ? text.replace(/\s*[\r\n]+\s*/g, ' ') : text);
                }

                this.scheduleSync();
            },

            onClick(event) {
                const surface = this.$refs.surface;

                // A click on a variable or a tag picks the whole of it, ready for replacing.
                const chip = event.target.closest('[data-tpl-var], [data-tpl-tag]');

                if (chip && surface.contains(chip)) {
                    this.select(chip);

                    return;
                }

                const edit = event.target.closest('[data-tpl-edit]');

                if (edit && surface.contains(edit)) {
                    // The Condition menu closes on any click outside it, and this click would
                    // reach the document right after opening it.
                    event.stopPropagation();
                    this.editCondition(edit.closest('[data-tpl-block]'));

                    return;
                }

                const remove = event.target.closest('[data-tpl-remove]');

                if (! remove || ! surface.contains(remove)) {
                    return;
                }

                // Removing a condition or a repeat keeps what was written inside it.
                const block = remove.closest('[data-tpl-block]');
                const body = block.querySelector(':scope > [data-tpl-body]');

                block.replaceWith(...(body ? [...body.childNodes] : []));
                this.ensureEditableEdges();
                this.scheduleSync();
            },

            onKeydown(event) {
                const shortcut = FORMAT_SHORTCUTS[event.key.toLowerCase()];

                // The browser's own shortcut would step over the variables, and plain text
                // takes no formatting at all.
                if (shortcut && (event.metaKey || event.ctrlKey) && ! event.altKey && ! event.shiftKey) {
                    event.preventDefault();
                    this.format(shortcut);

                    return;
                }

                if (event.key === 'Enter' && ! isRich) {
                    event.preventDefault();

                    if (! isInline) {
                        this.insertStreamText('\n');
                        this.scheduleSync();
                    }

                    return;
                }

                if (event.key !== 'Backspace' || ! isRich) {
                    return;
                }

                const selection = window.getSelection();

                if (! selection || selection.rangeCount === 0 || ! selection.isCollapsed) {
                    return;
                }

                const range = selection.getRangeAt(0);
                const body = elementOf(range.startContainer)?.closest('[data-tpl-body]');

                if (! body || ! this.$refs.surface.contains(body)) {
                    return;
                }

                // At the very start of a block, the browser would merge into the block header.
                const before = document.createRange();

                before.selectNodeContents(body);
                before.setEnd(range.startContainer, range.startOffset);

                if (before.toString() === '' && before.cloneContents().querySelector('[data-tpl-var]') === null) {
                    event.preventDefault();
                }
            },

            receive(detail) {
                if (detail?.name !== this.config.name) {
                    return;
                }

                if (detail.kind === 'variable') {
                    this.insertVariable(detail.path);
                } else {
                    this.insertBlock(detail.kind, detail.path);
                }
            },

            format(command) {
                this.closeMenu();

                if (this.mode !== 'visual' || ! isRich) {
                    return;
                }

                const range = this.restoreSelection();
                const tag = FORMAT_TAGS[command] ?? null;
                const chips = tag ? chipsWithin(this.$refs.surface, range) : [];
                const hasText = ! range.collapsed && textBesideChips(range) !== '';

                document.execCommand('styleWithCSS', false, false);

                if (chips.length === 0 || hasText) {
                    document.execCommand(command, false, null);
                }

                // The browser formats text but steps over non-editable elements, so the
                // variables in the selection follow the text here: bold text makes them bold;
                // variables picked on their own toggle.
                if (chips.length > 0) {
                    const apply = hasText
                        ? document.queryCommandState(command)
                        : ! chips.every((chip) => formattingAround(chip, tag) !== null);

                    chips.forEach((chip) => setFormatting(chip, tag, apply));

                    if (! hasText) {
                        const chipRange = document.createRange();
                        const selection = window.getSelection();

                        chipRange.setStartBefore(chips[0]);
                        chipRange.setEndAfter(chips[chips.length - 1]);
                        selection.removeAllRanges();
                        selection.addRange(chipRange);
                    }
                }

                this.rememberSelection();
                this.scheduleSync();
            },

            insertVariable(path) {
                this.closeMenu();

                if (this.mode !== 'visual') {
                    this.insertCode(`{% ${path} %}`);

                    return;
                }

                const range = this.restoreSelection();

                this.clearPlaceholder(range);
                range.deleteContents();

                const chip = this.chip(path, this.scope);
                // Rich text needs a non-breaking one: Chrome empties a plain space next to a
                // non-editable element as soon as typing starts. The codec writes it back as an
                // ordinary space. Plain text keeps its spaces as they are.
                const space = document.createTextNode(isRich ? ' ' : ' ');

                range.insertNode(space);
                range.insertNode(chip);

                this.placeCaret(space, 'after');
                this.scheduleSync();
            },

            /**
             * @param {{operator?: string, value?: string}} comparison  none: "has a value"
             */
            insertBlock(kind, path, comparison = {}) {
                this.closeMenu();

                const attributes = { path, alias: 'item', ...comparison };

                if (this.mode !== 'visual') {
                    this.insertCode(openingTag(kind, attributes), closingTag(kind));

                    return;
                }

                // The server refuses a loop named like the one around it.
                if (kind === 'for' && this.scope.length > 0) {
                    return;
                }

                if (! isRich) {
                    this.wrapInTags(kind, attributes);

                    return;
                }

                const range = this.restoreSelection();
                const anchor = this.topLevelBlock(range.startContainer);
                const { element, body } = this.block(kind, attributes, this.scope);
                const paragraph = this.paragraph();

                body.append(paragraph);

                if (anchor && isBlank(anchor)) {
                    anchor.replaceWith(element);
                } else if (anchor) {
                    anchor.after(element);
                } else {
                    this.$refs.surface.append(element);
                }

                this.ensureEditableEdges();
                this.placeCaret(paragraph, 'inside');
                this.scheduleSync();
            },

            /** In plain text, a condition or a repeat goes around the selection, as two tags. */
            wrapInTags(kind, attributes) {
                const range = this.restoreSelection();
                const scope = [...this.scope];
                const opening = this.tag({ kind, ...attributes }, openingTag(kind, attributes), scope);
                const closing = this.tag({ kind: kind === 'for' ? 'endfor' : 'endif' }, closingTag(kind), scope);
                const end = range.cloneRange();
                const start = range.cloneRange();

                end.collapse(false);
                end.insertNode(closing);
                start.collapse(true);
                start.insertNode(opening);

                this.placeCaret(range.collapsed ? opening : closing, 'after');
                this.ensureEditableEdges();
                this.scheduleSync();
            },

            /** Text typed or pasted into plain text, line breaks as `<br>`. */
            insertStreamText(text) {
                const range = this.restoreSelection();
                const fragment = document.createDocumentFragment();

                appendStreamText(fragment, text);

                const last = fragment.lastChild;

                range.deleteContents();

                if (! last) {
                    return;
                }

                range.insertNode(fragment);
                this.ensureEditableEdges();

                if (last.isConnected) {
                    this.placeCaret(last, 'after');
                }
            },

            insertCode(open, close = '') {
                const area = this.$refs.code;
                const start = area.selectionStart ?? area.value.length;
                const end = area.selectionEnd ?? start;
                const caret = end + open.length;

                this.value = area.value.slice(0, start) + open + area.value.slice(start, end) + close + area.value.slice(end);

                this.$nextTick(() => {
                    area.focus();
                    area.setSelectionRange(caret, caret);
                });
            },

            /** A list or a flag is only ever checked; anything else asks how to compare. */
            chooseCondition(path) {
                const { label, isList, isFlag, values } = this.describe(path, this.scope);

                if (isList || isFlag) {
                    this.insertBlock('if', path);

                    return;
                }

                this.condition = {
                    path,
                    label,
                    operator: values.length > 0 ? '==' : '',
                    value: '',
                    values,
                    editing: false,
                };
            },

            editCondition(block) {
                const { path, operator, value } = block.dataset;
                const { label, values } = this.describe(path, this.scopeAt(block.parentElement));

                this.closeMenu();
                editingBlock = block;
                this.condition = { path, label, operator: operator ?? '', value: value ?? '', values, editing: true };
                this.menu = 'if';
                this.$refs.toolbar.scrollIntoView({ block: 'nearest' });
            },

            /** The listed values, plus the current one when it is not among them (typed as code). */
            conditionValues() {
                const { values, value } = this.condition;

                return value !== '' && ! values.includes(value) ? [value, ...values] : values;
            },

            conditionIsComplete() {
                return this.condition !== null
                    && (this.condition.operator === '' || cleanValue(this.condition.value) !== '');
            },

            applyCondition() {
                if (! this.conditionIsComplete()) {
                    return;
                }

                const { path, operator } = this.condition;
                const comparison = operator === '' ? {} : { operator, value: cleanValue(this.condition.value) };
                const block = editingBlock;

                if (! block) {
                    this.insertBlock('if', path, comparison);

                    return;
                }

                this.closeMenu();

                const { element: fresh } = this.block('if', { path, ...comparison }, this.scopeAt(block.parentElement));

                delete block.dataset.operator;
                delete block.dataset.value;
                Object.assign(block.dataset, comparison);
                block.querySelector(':scope > [data-tpl-header]')?.replaceWith(fresh.firstElementChild);
                this.scheduleSync();
            },

            /**
             * The variables to insert: the fields of the loops around the caret (of every
             * list, as code, where the caret's loop is unknown) and every variable that prints
             * something. A flag prints nothing, so it is only offered as a condition.
             */
            variableOptions() {
                return [
                    ...this.loopFieldOptions(false),
                    ...this.config.variables
                        .filter((variable) => ! variable.isFlag)
                        .map((variable) => ({ path: variable.path, label: variable.path, description: variable.description })),
                ];
            },

            conditionOptions() {
                return [
                    ...this.loopFieldOptions(null),
                    ...this.config.variables
                        .map((variable) => ({ path: variable.path, label: variable.path, description: variable.description })),
                    ...this.config.collections.map((collection) => ({
                        path: collection.path,
                        label: collection.path,
                        description: `${collection.description} · ${this.label('hasItems')}`,
                    })),
                ];
            },

            /** @param {boolean|null} flags  false: no flags; null: every field */
            loopFieldOptions(flags) {
                const loops = this.mode === 'visual'
                    ? this.scope
                    : this.config.collections.map((collection) => ({ alias: 'item', path: collection.path }));

                return loops.flatMap(({ alias, path }) => (this.collection(path)?.fields ?? [])
                    .filter((field) => flags === null || Boolean(field.isFlag) === flags)
                    .map((field) => ({
                        path: `${alias}.${field.name}`,
                        label: `${alias}.${field.name}`,
                        description: field.description,
                    })));
            },

            loopOptions() {
                return this.config.collections.map((collection) => ({
                    path: collection.path,
                    label: collection.path,
                    description: collection.description,
                }));
            },

            collection(path) {
                return this.config.collections.find((collection) => collection.path === path) ?? null;
            },

            /** What the schema lists for a path; a loop field is listed through its list. */
            valuesOf(path) {
                return (this.config.values ?? {})[path] ?? [];
            },

            describe(path, scope) {
                const [root, ...rest] = path.split('.');
                const loop = [...scope].reverse().find((entry) => entry.alias === root);

                if (loop) {
                    const fieldName = rest.join('.');
                    const field = this.collection(loop.path)?.fields.find((candidate) => candidate.name === fieldName);

                    return {
                        label: path,
                        description: field?.description ?? '',
                        known: Boolean(field),
                        isList: false,
                        isFlag: Boolean(field?.isFlag),
                        values: this.valuesOf(`${loop.path}.${fieldName}`),
                    };
                }

                const variable = this.config.variables.find((candidate) => candidate.path === path);

                if (variable) {
                    return {
                        label: path,
                        description: variable.description,
                        known: true,
                        isList: false,
                        isFlag: Boolean(variable.isFlag),
                        values: this.valuesOf(path),
                    };
                }

                const collection = this.collection(path);

                return collection
                    ? { label: collection.description, description: collection.description, known: true, isList: true, isFlag: false, values: [] }
                    : { label: path, description: '', known: false, isList: false, isFlag: false, values: [] };
            },

            builders() {
                return {
                    paragraph: (withBreak) => this.paragraph(withBreak),
                    chip: (path, scope) => this.chip(path, scope),
                    block: (kind, attributes, scope) => this.block(kind, attributes, scope),
                    tag: (tag, raw, scope) => this.tag(tag, raw, scope),
                };
            },

            paragraph(withBreak = true) {
                const paragraph = document.createElement('p');

                if (withBreak) {
                    paragraph.append(document.createElement('br'));
                }

                return paragraph;
            },

            chip(path, scope) {
                const { label, description, known } = this.describe(path, scope);
                const chip = document.createElement('span');

                chip.dataset.tplVar = path;
                chip.contentEditable = 'false';
                chip.className = `${CHIP_CLASS} ${known ? CHIP_KNOWN_CLASS : CHIP_UNKNOWN_CLASS}`;
                chip.title = known ? description : this.label('notAvailable').replace(':path', path);
                chip.textContent = label;

                return chip;
            },

            /** A condition or repeat tag of plain text, written back exactly as `raw`. */
            tag(tag, raw, scope) {
                const chip = document.createElement('span');

                chip.dataset.tplTag = tag.kind;
                chip.dataset.tplRaw = raw;
                chip.contentEditable = 'false';
                chip.className = `${CHIP_CLASS} ${TAG_CHIP_CLASS[tag.kind]}`;
                chip.title = raw;

                if (tag.kind === 'for') {
                    chip.dataset.alias = tag.alias || 'item';
                    chip.dataset.path = tag.path;
                }

                if (tag.kind === 'if' || tag.kind === 'for') {
                    chip.append(...this.title(tag.kind, tag, scope, 'font-semibold'));
                } else {
                    chip.textContent = this.label({ else: 'otherwise', endif: 'endCondition', endfor: 'endRepeat' }[tag.kind]);
                }

                return chip;
            },

            /** "Show only if … has a value", "Repeat for each item in …", as nodes. */
            title(kind, { path, operator, value }, scope, nameClass) {
                const { label, isList, isFlag } = this.describe(path, scope);
                const name = document.createElement('strong');

                name.className = nameClass;
                name.textContent = label;

                if (kind === 'for') {
                    return sentence(this.label('repeatFor'), { list: name });
                }

                if (operator) {
                    const compared = document.createElement('strong');

                    compared.className = nameClass;
                    compared.textContent = `"${value ?? ''}"`;

                    return sentence(this.label(operator === '!=' ? 'showIfIsNot' : 'showIfIs'), { variable: name, value: compared });
                }

                const key = isList ? 'showIfHasItems' : (isFlag ? 'showIfTrue' : 'showIfHasValue');

                return sentence(this.label(key), { variable: name });
            },

            block(kind, { path, alias, operator, value }, scope) {
                const { isList, isFlag } = this.describe(path, scope);
                const element = document.createElement('div');
                const header = document.createElement('div');
                const title = document.createElement('span');
                const actions = document.createElement('span');
                const remove = document.createElement('button');
                const body = document.createElement('div');

                element.dataset.tplBlock = kind;
                element.dataset.path = path;
                element.className = BLOCK_CLASS[kind];

                if (kind === 'for') {
                    element.dataset.alias = alias || 'item';
                }

                if (kind === 'if' && operator) {
                    element.dataset.operator = operator;
                    element.dataset.value = value ?? '';
                }

                title.append(...this.title(kind, { path, operator, value }, scope, 'font-semibold text-label'));
                title.className = 'min-w-0';
                actions.className = 'flex items-center gap-3 shrink-0';

                if (kind === 'if' && ! isList && ! isFlag) {
                    const edit = document.createElement('button');

                    edit.type = 'button';
                    edit.dataset.tplEdit = '';
                    edit.className = 'text-[0.75rem] text-secondary-label hover:text-tint';
                    edit.textContent = this.label('edit');
                    actions.append(edit);
                }

                remove.type = 'button';
                remove.dataset.tplRemove = '';
                remove.className = 'text-[0.75rem] text-secondary-label hover:text-danger';
                remove.textContent = this.label(kind === 'for' ? 'stopRepeating' : 'removeCondition');
                actions.append(remove);

                header.dataset.tplHeader = '';
                header.contentEditable = 'false';
                header.className = 'flex items-center justify-between gap-2 px-2 py-1 text-[0.75rem] text-secondary-label select-none';
                header.append(title, actions);

                body.dataset.tplBody = '';
                body.className = 'px-3 pb-2';

                element.append(header, body);

                return { element, body };
            },

            select(node) {
                const range = document.createRange();
                const selection = window.getSelection();

                range.selectNode(node);
                selection.removeAllRanges();
                selection.addRange(range);
                this.rememberSelection();
            },

            rememberSelection() {
                const surface = this.$refs.surface;
                const selection = window.getSelection();

                if (! surface || ! selection || selection.rangeCount === 0) {
                    return;
                }

                const range = selection.getRangeAt(0);
                const inside = surface.contains(range.commonAncestorContainer)
                    && ! elementOf(range.startContainer)?.closest('[data-tpl-header]');

                this.highlightChips(inside ? range : null);

                if (! inside) {
                    return;
                }

                savedRange = range.cloneRange();
                this.scope = this.scopeFor(range);
            },

            /** Variables show they are part of a selection; the browser paints nothing on them. */
            highlightChips(range) {
                for (const chip of this.$refs.surface.querySelectorAll('[data-tpl-var], [data-tpl-tag]')) {
                    const selected = range !== null && ! range.collapsed && range.intersectsNode(chip);

                    CHIP_SELECTED_CLASSES.forEach((name) => chip.classList.toggle(name, selected));
                }
            },

            restoreSelection() {
                const surface = this.$refs.surface;
                const selection = window.getSelection();

                surface.focus({ preventScroll: true });

                if (savedRange && surface.contains(savedRange.startContainer)) {
                    selection.removeAllRanges();
                    selection.addRange(savedRange);

                    return savedRange;
                }

                const range = document.createRange();

                if (! isRich) {
                    // Nothing picked yet: the end of the text, before the room left for the caret.
                    const filler = surface.lastChild?.nodeName === 'BR' && surface.lastChild.hasAttribute('data-tpl-filler')
                        ? surface.lastChild
                        : null;

                    range.selectNodeContents(surface);
                    range.collapse(false);

                    if (filler) {
                        range.setStartBefore(filler);
                        range.collapse(true);
                    }
                } else {
                    // Nothing picked yet: the end of its last paragraph, or a new paragraph
                    // after a block.
                    let target = surface.lastElementChild;

                    if (target && (target.tagName === 'UL' || target.tagName === 'OL')) {
                        target = target.lastElementChild ?? target;
                    }

                    if (! target || target.dataset.tplBlock) {
                        target = this.paragraph();
                        surface.append(target);
                    }

                    range.selectNodeContents(target);
                    range.collapse(false);
                }

                selection.removeAllRanges();
                selection.addRange(range);
                savedRange = range.cloneRange();
                this.scope = this.scopeFor(range);

                return range;
            },

            placeCaret(node, where) {
                const range = document.createRange();
                const selection = window.getSelection();

                if (where === 'after') {
                    range.setStartAfter(node);
                } else {
                    range.selectNodeContents(node);
                }

                range.collapse(true);
                selection.removeAllRanges();
                selection.addRange(range);
                savedRange = range.cloneRange();
                this.scope = this.scopeFor(range);
            },

            /** An empty paragraph holds a <br> so it can take the caret; content replaces it. */
            clearPlaceholder(range) {
                if (! isRich) {
                    return;
                }

                const holder = elementOf(range.startContainer);

                if (holder && (holder === this.$refs.surface || ['P', 'LI'].includes(holder.tagName))
                    && holder.childNodes.length === 1 && holder.firstChild.nodeName === 'BR') {
                    holder.firstChild.remove();
                    range.selectNodeContents(holder);
                    range.collapse(true);
                }
            },

            topLevelBlock(node) {
                const surface = this.$refs.surface;
                let element = elementOf(node);

                while (element && element !== surface) {
                    const parent = element.parentElement;

                    if (parent === surface || parent?.hasAttribute('data-tpl-body')) {
                        return element;
                    }

                    element = parent;
                }

                return null;
            },

            /** The loops around a caret: block ancestors in rich text, open tags before it in plain text. */
            scopeFor(range) {
                if (isRich) {
                    return this.scopeAt(range.startContainer);
                }

                const before = document.createRange();
                const scope = [];

                before.selectNodeContents(this.$refs.surface);
                before.setEnd(range.startContainer, range.startOffset);

                for (const tag of before.cloneContents().querySelectorAll('[data-tpl-tag]')) {
                    if (tag.dataset.tplTag === 'for') {
                        scope.push({ alias: tag.dataset.alias, path: tag.dataset.path });
                    } else if (tag.dataset.tplTag === 'endfor') {
                        scope.pop();
                    }
                }

                return scope;
            },

            scopeAt(node) {
                const scope = [];
                let element = elementOf(node);

                while (element && element !== this.$refs.surface) {
                    if (element.dataset?.tplBlock === 'for') {
                        scope.unshift({ alias: element.dataset.alias, path: element.dataset.path });
                    }

                    element = element.parentElement;
                }

                return scope;
            },

            /**
             * Rich text: without a paragraph around a block, the caret has nowhere to go before
             * or after it. Plain text: a last line break needs a second one to show the empty
             * line it starts.
             */
            ensureEditableEdges() {
                const surface = this.$refs.surface;

                if (! isRich) {
                    const last = surface.lastChild;

                    if (last?.nodeName === 'BR' && ! last.hasAttribute('data-tpl-filler')) {
                        const filler = document.createElement('br');

                        filler.dataset.tplFiller = '';
                        surface.append(filler);
                    }

                    return;
                }

                for (const container of [surface, ...surface.querySelectorAll('[data-tpl-body]')]) {
                    if (container.firstElementChild?.dataset.tplBlock) {
                        container.prepend(this.paragraph());
                    }

                    if (container.lastElementChild?.dataset.tplBlock) {
                        container.append(this.paragraph());
                    }
                }
            },

            /** A block header the browser deleted along with a selection is drawn again. */
            repairBlocks() {
                for (const element of this.$refs.surface.querySelectorAll('[data-tpl-block]')) {
                    if (element.querySelector(':scope > [data-tpl-header]')) {
                        continue;
                    }

                    const { tplBlock: kind, path, alias, operator, value } = element.dataset;
                    const { element: fresh } = this.block(kind, { path, alias, operator, value }, this.scopeAt(element.parentElement));

                    element.prepend(fresh.firstElementChild);
                }
            },
        };
    });
});

/**
 * A translated sentence with its `:placeholders` replaced by elements, as nodes to append:
 * the words around a variable stay in the order the language puts them.
 */
function sentence(text, parts) {
    return text
        .split(/(:[a-z]+)/)
        .filter((piece) => piece !== '')
        .map((piece) => (piece.startsWith(':') && parts[piece.slice(1)]) || piece);
}

function elementOf(node) {
    if (! node) {
        return null;
    }

    return node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
}

function isBlank(element) {
    return ['P', 'DIV'].includes(element.tagName)
        && ! element.dataset.tplBlock
        && element.textContent.trim() === ''
        && element.querySelector('[data-tpl-var]') === null;
}

/** A quote would end the value inside the tag, and `{%` / `%}` would start or end a tag. */
function cleanValue(value) {
    return String(value ?? '').replace(/"/g, '').replace(/\{%|%\}/g, '').trim();
}

function chipsWithin(surface, range) {
    if (range.collapsed) {
        return [];
    }

    return [...surface.querySelectorAll('[data-tpl-var]')].filter((chip) => range.intersectsNode(chip));
}

function textBesideChips(range) {
    const contents = range.cloneContents();

    contents.querySelectorAll('[data-tpl-var], [data-tpl-header]').forEach((node) => node.remove());

    return contents.textContent.trim();
}

function formattingAround(node, tag) {
    for (let element = node.parentElement; element && ! BOUNDARY_TAGS.includes(element.tagName); element = element.parentElement) {
        if (element.matches(FORMAT_SELECTORS[tag])) {
            return element;
        }
    }

    return null;
}

function setFormatting(chip, tag, apply) {
    if (apply) {
        if (formattingAround(chip, tag) === null) {
            const wrapper = document.createElement(tag);

            chip.before(wrapper);
            wrapper.append(chip);
        }

        return;
    }

    let ancestor;

    while ((ancestor = formattingAround(chip, tag)) !== null) {
        splitAround(ancestor, chip);
    }
}

/** Takes `node` out of `ancestor`: what came before and after it stays wrapped in copies. */
function splitAround(ancestor, node) {
    const after = document.createRange();
    const before = document.createRange();

    after.setStartAfter(node);
    after.setEnd(ancestor, ancestor.childNodes.length);
    before.setStart(ancestor, 0);
    before.setEndBefore(node);

    const tail = after.extractContents();
    const head = before.extractContents();

    for (const [fragment, place] of [[head, 'before'], [tail, 'after']]) {
        if (fragment.textContent !== '' || fragment.querySelector('[data-tpl-var]') !== null) {
            const copy = ancestor.cloneNode(false);

            copy.append(fragment);
            ancestor[place](copy);
        }
    }

    ancestor.replaceWith(...ancestor.childNodes);
}
