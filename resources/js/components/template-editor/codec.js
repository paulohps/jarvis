/**
 * Converts between a stored `{% %}` template and the DOM the visual template editor works on
 * (ported from my-finance).
 *
 * Two shapes of template:
 *
 * - rich (an e-mail or notification body): `{% %}` tags inside the light HTML the server keeps
 *   (HtmlSanitizer), with conditions and repeats around whole paragraphs;
 * - stream (a title, an e-mail subject, a WhatsApp message): plain text, kept exactly as it was
 *   written — line breaks, spaces and any `<` included — with variables as chips and each
 *   `{% if %}` / `{% else %}` / `{% endif %}` / `{% for %}` / `{% endfor %}` tag as a chip of
 *   its own in the flow of the text.
 *
 * The server stays the authority: whatever comes out of here is validated (ValidTemplate)
 * and, for a body, sanitized again (TemplateRenderer::renderHtml). What this module owns is
 * only whether a template can be shown visually at all; anything it cannot represent
 * faithfully raises UnsupportedTemplate, and the editor falls back to code instead of silently
 * rewriting it. The error carries a `code` the editor turns into a sentence in the reader's
 * language.
 */

const TAG_PATTERN = /\{%([\s\S]*?)%\}/g;
const PATH_PATTERN = /^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/;
const IDENTIFIER_PATTERN = /^[A-Za-z_][A-Za-z0-9_]*$/;

/** TemplateParser::RESERVED. */
const RESERVED = ['if', 'else', 'endif', 'for', 'endfor', 'in'];

/** `if invoice.currency == "BRL"`: the same shape TemplateParser accepts. */
const COMPARISON_PATTERN = /^if\s+([A-Za-z0-9_.]+)\s*(==|!=)\s*"([^"]*)"$/;

/**
 * The markup InvoiceMail looks for before it turns line breaks into `<br>`: a body without any
 * of it was written as plain text.
 */
const BLOCK_MARKUP_PATTERN = /<(p|br|div|ul|ol|h[1-6]|table)\b/i;

/** A line holding nothing but one control tag. */
const CONTROL_LINE_PATTERN = /^\s*\{%\s*(if|else|endif|for|endfor)\b[^%]*%\}\s*$/;

/** Formatting the editor keeps, written the way the server stores it. */
const INLINE_TAGS = { B: 'strong', STRONG: 'strong', I: 'em', EM: 'em', U: 'u' };

/** Removed with their content — HtmlSanitizer::DROPPED_WITH_CONTENT, kept in step by hand. */
const DROPPED_TAGS = [
    'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'NOSCRIPT', 'TEMPLATE',
    'SVG', 'MATH', 'FORM', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA',
    'LINK', 'META', 'BASE', 'TITLE', 'HEAD', 'FRAME', 'FRAMESET', 'APPLET', 'IMG',
];

/**
 * A template the visual editor cannot show. `code` names the reason (the editor translates
 * it); `detail` is the piece of the template involved, when there is one.
 */
export class UnsupportedTemplate extends Error {
    constructor(code, detail = '') {
        super(detail === '' ? code : `${code}: ${detail}`);

        this.code = code;
        this.detail = detail;
    }
}

/**
 * What a `{% %}` tag is, in the grammar TemplateParser accepts:
 * `{ kind: 'var', path }`, `{ kind: 'if', path, operator?, value? }`, `{ kind: 'else' }`,
 * `{ kind: 'endif' }`, `{ kind: 'for', alias, path }` or `{ kind: 'endfor' }`.
 *
 * @throws {UnsupportedTemplate} for anything else
 */
export function classifyTag(match, inner) {
    const trimmed = inner.trim();
    const comparison = trimmed.match(COMPARISON_PATTERN);

    if (comparison && isPath(comparison[1])) {
        const [, path, operator, value] = comparison;

        return { kind: 'if', path, operator, value };
    }

    const words = trimmed.split(/\s+/);
    const [keyword] = words;

    if (words.length === 1 && isPath(keyword) && ! RESERVED.includes(keyword)) {
        return { kind: 'var', path: keyword };
    }

    if (keyword === 'if' && words.length === 2 && isPath(words[1])) {
        return { kind: 'if', path: words[1] };
    }

    if (keyword === 'for' && words.length === 4 && words[2] === 'in'
        && IDENTIFIER_PATTERN.test(words[1]) && ! RESERVED.includes(words[1]) && isPath(words[3])) {
        return { kind: 'for', alias: words[1], path: words[3] };
    }

    if (['else', 'endif', 'endfor'].includes(keyword) && words.length === 1) {
        return { kind: keyword };
    }

    throw new UnsupportedTemplate('unknown_tag', match);
}

/**
 * A body written as plain text (the editor before this one was a textarea) as the paragraphs
 * it reads as: InvoiceMail turns the line breaks of such a body into `<br>`, so a blank line
 * starts a paragraph, a single line break stays one, and a line holding only a condition or a
 * repeat tag goes between the paragraphs. A body with any block markup is left alone.
 */
export function paragraphsFromText(template) {
    if (template.trim() === '' || BLOCK_MARKUP_PATTERN.test(template)) {
        return template;
    }

    let output = '';
    let lines = [];

    const flush = () => {
        if (lines.length > 0) {
            output += `<p>${lines.join('<br>')}</p>`;
        }

        lines = [];
    };

    for (const line of template.replace(/\r\n?/g, '\n').split('\n')) {
        if (line.trim() === '') {
            flush();
        } else if (CONTROL_LINE_PATTERN.test(line)) {
            flush();
            output += line.trim();
        } else {
            lines.push(line.trim());
        }
    }

    flush();

    return output;
}

/**
 * Builds the editor content for a rich template.
 *
 * `build` creates the editor's own elements: paragraph(withBreak), chip(path, scope) and
 * block(kind, {path, alias, operator, value}, scope) → {element, body}. `scope` lists the
 * loops around a node, outermost first, so a chip for `item.description` knows which list it
 * belongs to.
 *
 * @throws {UnsupportedTemplate}
 */
export function parseTemplate(template, build) {
    const marked = markTags(paragraphsFromText(template ?? ''));

    if (marked.includes('{%')) {
        throw new UnsupportedTemplate('unfinished_tag');
    }

    // Parsed in a detached document and rebuilt node by node: nothing from the stored
    // template is ever assigned as HTML into the page.
    const parsed = new DOMParser().parseFromString(`<!doctype html><body>${marked}</body>`, 'text/html');
    const fragment = document.createDocumentFragment();

    appendBlocks(fragment, [...parsed.body.childNodes], build, []);

    if (! fragment.firstChild) {
        fragment.append(build.paragraph());
    }

    return fragment;
}

/**
 * Writes the rich editor content back as a template.
 */
export function serializeBlocks(container) {
    let output = '';
    let loose = { html: '', meaningful: false };

    // Text the browser left directly in a container, outside any paragraph.
    const flushLoose = () => {
        if (loose.meaningful) {
            output += `<p>${loose.html}</p>`;
        }

        loose = { html: '', meaningful: false };
    };

    for (const node of container.childNodes) {
        if (isElement(node) && node.dataset.tplBlock) {
            flushLoose();

            // A block whose body the browser merged away still holds its content directly.
            const body = node.querySelector(':scope > [data-tpl-body]');
            const inner = serializeBlocks(body ?? node);

            output += `${openingTag(node.dataset.tplBlock, node.dataset)}${inner}${closingTag(node.dataset.tplBlock)}`;

            continue;
        }

        if (isElement(node) && node.hasAttribute('data-tpl-header')) {
            continue;
        }

        if (isElement(node) && (node.tagName === 'UL' || node.tagName === 'OL')) {
            flushLoose();
            output += serializeList(node);

            continue;
        }

        if (isElement(node) && node.tagName === 'DIV' && hasBlockChildren(node)) {
            flushLoose();
            output += serializeBlocks(node);

            continue;
        }

        if (isElement(node) && (node.tagName === 'P' || node.tagName === 'DIV')) {
            flushLoose();

            const paragraph = serializeInline(node.childNodes);

            if (paragraph.meaningful) {
                output += `<p>${paragraph.html}</p>`;
            }

            continue;
        }

        const piece = serializeInline([node]);

        loose = { html: loose.html + piece.html, meaningful: loose.meaningful || piece.meaningful };
    }

    flushLoose();

    return output;
}

/**
 * Builds the editor content for a stream template: text with its line breaks as `<br>`,
 * variables as chips and control tags as chips that keep the tag as it was written.
 *
 * `build` creates chip(path, scope) and tag(tag, raw, scope), `tag` being what classifyTag
 * returns.
 *
 * @throws {UnsupportedTemplate}
 */
export function parseStream(template, build) {
    const source = template ?? '';
    const fragment = document.createDocumentFragment();
    const scope = [];
    let last = 0;

    for (const match of source.matchAll(TAG_PATTERN)) {
        appendStreamText(fragment, source.slice(last, match.index));

        const tag = classifyTag(match[0], match[1]);

        if (tag.kind === 'var') {
            fragment.append(build.chip(tag.path, [...scope]));
        } else {
            fragment.append(build.tag(tag, match[0], [...scope]));

            if (tag.kind === 'for') {
                scope.push({ alias: tag.alias, path: tag.path });
            } else if (tag.kind === 'endfor') {
                scope.pop();
            }
        }

        last = match.index + match[0].length;
    }

    const rest = source.slice(last);

    if (rest.includes('{%')) {
        throw new UnsupportedTemplate('unfinished_tag');
    }

    appendStreamText(fragment, rest);

    return fragment;
}

/**
 * Writes a stream back, character for character. A `<br data-tpl-filler>` only gives an
 * empty last line room for the caret: it counts once something follows it.
 */
export function serializeStream(container) {
    let output = '';
    let pending = '';

    const write = (text) => {
        if (text === '') {
            return;
        }

        output += pending + text;
        pending = '';
    };

    const walk = (nodes) => {
        for (const node of nodes) {
            if (node.nodeType === Node.TEXT_NODE) {
                write(node.textContent.replace(/​/g, ''));

                continue;
            }

            if (! isElement(node)) {
                continue;
            }

            if (node.dataset.tplVar) {
                write(`{% ${node.dataset.tplVar} %}`);

                continue;
            }

            if (node.dataset.tplTag) {
                write(node.dataset.tplRaw);

                continue;
            }

            if (node.tagName === 'BR') {
                if (node.hasAttribute('data-tpl-filler')) {
                    pending += '\n';
                } else {
                    write('\n');
                }

                continue;
            }

            // A line the browser wrapped in a block of its own starts on a new line.
            if (['DIV', 'P', 'LI'].includes(node.tagName) && output !== '' && ! output.endsWith('\n')) {
                write('\n');
            }

            walk(node.childNodes);
        }
    };

    walk(container.childNodes);

    return output;
}

/** Text with its line breaks as `<br>`, appended to `target`. */
export function appendStreamText(target, text) {
    text.replace(/\r\n?/g, '\n').split('\n').forEach((line, index) => {
        if (index > 0) {
            target.append(document.createElement('br'));
        }

        if (line !== '') {
            target.append(document.createTextNode(line));
        }
    });
}

/** The tag that opens a condition or a repeat. */
export function openingTag(kind, { path, alias, operator, value }) {
    if (kind === 'for') {
        return `{% for ${alias || 'item'} in ${path} %}`;
    }

    return operator
        ? `{% if ${path} ${operator} "${value ?? ''}" %}`
        : `{% if ${path} %}`;
}

export function closingTag(kind) {
    return kind === 'for' ? '{% endfor %}' : '{% endif %}';
}

function isPath(path) {
    return PATH_PATTERN.test(path) && ! path.split('.').some((segment) => RESERVED.includes(segment));
}

function markTags(template) {
    return template.replace(TAG_PATTERN, (match, inner) => {
        const tag = classifyTag(match, inner);

        switch (tag.kind) {
            case 'var':
                return `<tpl-var data-path="${tag.path}"></tpl-var>`;
            case 'if':
                return tag.operator
                    ? `<tpl-open data-kind="if" data-path="${tag.path}" data-operator="${tag.operator}" data-value="${escapeAttribute(tag.value)}"></tpl-open>`
                    : `<tpl-open data-kind="if" data-path="${tag.path}"></tpl-open>`;
            case 'for':
                return `<tpl-open data-kind="for" data-alias="${tag.alias}" data-path="${tag.path}"></tpl-open>`;
            case 'endif':
                return '<tpl-close data-kind="if"></tpl-close>';
            case 'endfor':
                return '<tpl-close data-kind="for"></tpl-close>';
            default:
                throw new UnsupportedTemplate('else_branch');
        }
    });
}

function appendBlocks(target, nodes, build, scope) {
    let paragraph = null;

    const flush = () => {
        if (paragraph && hasContent(paragraph)) {
            target.append(paragraph);
        }

        paragraph = null;
    };

    for (let index = 0; index < nodes.length; index++) {
        const node = nodes[index];
        const tag = tagOf(node);

        if (tag === 'TPL-OPEN') {
            flush();

            const end = matchingClose(nodes, index);

            if (end === -1) {
                throw new UnsupportedTemplate('partial_block');
            }

            const { kind, path, alias, operator, value } = node.dataset;
            const innerScope = kind === 'for' ? [...scope, { alias, path }] : scope;
            const { element, body } = build.block(kind, { path, alias, operator, value }, scope);

            appendBlocks(body, nodes.slice(index + 1, end), build, innerScope);

            if (! body.firstChild) {
                body.append(build.paragraph());
            }

            target.append(element);
            index = end;

            continue;
        }

        if (tag === 'TPL-CLOSE') {
            throw new UnsupportedTemplate('orphan_end');
        }

        if (tag !== null && DROPPED_TAGS.includes(tag)) {
            continue;
        }

        if (tag === 'DIV') {
            flush();
            appendBlocks(target, [...node.childNodes], build, scope);

            continue;
        }

        if (tag === 'P') {
            flush();

            const block = build.paragraph(false);

            appendInline(block, [...node.childNodes], build, scope);

            if (hasContent(block)) {
                target.append(block);
            }

            continue;
        }

        if (tag === 'UL' || tag === 'OL') {
            flush();
            target.append(buildList(node, build, scope));

            continue;
        }

        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim() === '' && paragraph === null) {
            continue;
        }

        paragraph ??= build.paragraph(false);
        appendInline(paragraph, [node], build, scope);
    }

    flush();
}

function appendInline(target, nodes, build, scope) {
    for (const node of nodes) {
        if (node.nodeType === Node.TEXT_NODE) {
            target.append(document.createTextNode(node.textContent));

            continue;
        }

        if (! isElement(node)) {
            continue;
        }

        const tag = tagOf(node);

        if (tag === 'TPL-VAR') {
            target.append(build.chip(node.dataset.path, scope));

            continue;
        }

        if (tag === 'TPL-OPEN' || tag === 'TPL-CLOSE') {
            throw new UnsupportedTemplate('block_in_text');
        }

        if (DROPPED_TAGS.includes(tag)) {
            continue;
        }

        if (tag === 'BR') {
            target.append(document.createElement('br'));

            continue;
        }

        if (INLINE_TAGS[tag]) {
            const element = document.createElement(INLINE_TAGS[tag]);

            appendInline(element, [...node.childNodes], build, scope);
            target.append(element);

            continue;
        }

        if (tag === 'SMALL') {
            throw new UnsupportedTemplate('small_text');
        }

        if (['P', 'DIV', 'UL', 'OL', 'LI'].includes(tag)) {
            throw new UnsupportedTemplate('paragraph_in_text');
        }

        // span, a, headings…: the server unwraps them too, so their text is all that renders.
        appendInline(target, [...node.childNodes], build, scope);
    }
}

function buildList(list, build, scope) {
    const element = document.createElement(list.tagName.toLowerCase());

    for (const child of list.childNodes) {
        if (child.nodeType === Node.TEXT_NODE && child.textContent.trim() === '') {
            continue;
        }

        if (isElement(child) && child.tagName.startsWith('TPL-') && child.tagName !== 'TPL-VAR') {
            throw new UnsupportedTemplate('block_in_list');
        }

        const item = document.createElement('li');
        const parts = isElement(child) && child.tagName === 'LI' ? [...child.childNodes] : [child];

        for (const part of parts) {
            if (isElement(part) && (part.tagName === 'UL' || part.tagName === 'OL')) {
                item.append(buildList(part, build, scope));
            } else {
                appendInline(item, [part], build, scope);
            }
        }

        element.append(item);
    }

    return element;
}

function matchingClose(nodes, start) {
    let depth = 0;

    for (let index = start; index < nodes.length; index++) {
        const node = nodes[index];

        if (! isElement(node)) {
            continue;
        }

        if (node.tagName === 'TPL-OPEN') {
            depth++;
        }

        if (node.tagName === 'TPL-CLOSE') {
            depth--;

            if (depth === 0) {
                return node.dataset.kind === nodes[start].dataset.kind ? index : -1;
            }
        }
    }

    return -1;
}

function serializeList(list) {
    const tag = list.tagName.toLowerCase();
    let items = '';

    for (const child of list.childNodes) {
        if (isElement(child) && child.tagName === 'LI') {
            let html = '';
            let meaningful = false;

            for (const part of child.childNodes) {
                if (isElement(part) && (part.tagName === 'UL' || part.tagName === 'OL')) {
                    html += serializeList(part);
                    meaningful = true;

                    continue;
                }

                const piece = serializeInline([part]);

                html += piece.html;
                meaningful ||= piece.meaningful;
            }

            if (meaningful) {
                items += `<li>${trimBreaks(html)}</li>`;
            }

            continue;
        }

        const piece = serializeInline([child]);

        if (piece.meaningful) {
            items += `<li>${piece.html}</li>`;
        }
    }

    return items === '' ? '' : `<${tag}>${items}</${tag}>`;
}

function serializeInline(nodes) {
    let html = '';
    let meaningful = false;

    for (const node of nodes) {
        if (node.nodeType === Node.TEXT_NODE) {
            const text = node.textContent.replace(/​/g, '').replace(/ /g, ' ');

            meaningful ||= text.trim() !== '';
            html += escapeHtml(text);

            continue;
        }

        if (! isElement(node) || node.hasAttribute('data-tpl-header')) {
            continue;
        }

        if (node.dataset.tplVar) {
            html += `{% ${node.dataset.tplVar} %}`;
            meaningful = true;

            continue;
        }

        if (node.tagName === 'BR') {
            html += '<br>';

            continue;
        }

        const inner = serializeInline(node.childNodes);
        const tag = INLINE_TAGS[node.tagName];

        meaningful ||= inner.meaningful;
        html += tag && inner.html !== '' ? `<${tag}>${inner.html}</${tag}>` : inner.html;
    }

    return { html: mergeAdjacentFormatting(trimBreaks(html)), meaningful };
}

/**
 * `<strong>a</strong><strong>b</strong>` → `<strong>ab</strong>`. A variable formatted on its
 * own gets a wrapper of its own, right next to the text's.
 */
function mergeAdjacentFormatting(html) {
    let merged = html;
    let previous;

    do {
        previous = merged;
        merged = merged.replace(/<\/(strong|em|u)><\1>/g, '');
    } while (merged !== previous);

    return merged;
}

function hasContent(element) {
    return element.textContent.trim() !== '' || element.querySelector('[data-tpl-var]') !== null;
}

function hasBlockChildren(element) {
    return [...element.children].some((child) => ['P', 'DIV', 'UL', 'OL'].includes(child.tagName) || child.dataset.tplBlock);
}

function trimBreaks(html) {
    return html.replace(/^(<br>)+|(<br>)+$/g, '');
}

function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Only for the detached parse: the value comes back decoded from the element's dataset. */
function escapeAttribute(text) {
    return escapeHtml(text).replace(/"/g, '&quot;');
}

function isElement(node) {
    return node.nodeType === Node.ELEMENT_NODE;
}

/** Upper case for every element: svg and math keep their lower-case names in an HTML document. */
function tagOf(node) {
    return isElement(node) ? node.tagName.toUpperCase() : null;
}
