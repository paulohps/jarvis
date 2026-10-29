<?php

namespace App\Services\Templates;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Allowlist sanitizer for template bodies (notifications, invoice e-mails),
 * ported from my-finance, on the HTML5 parser of PHP 8.4
 * (`\Dom\HTMLDocument`).
 *
 * Only light formatting survives: every attribute is dropped (so no `href`,
 * no `style`, no `on*` handler), executable containers go away with their
 * content, and any other element is unwrapped down to its text. Links are
 * never written in a body — they come from the actions the type declares —
 * so an anchor, `javascript:` or not, keeps only its text.
 */
final class HtmlSanitizer
{
    /**
     * @var list<string>
     */
    public const array ALLOWED_TAGS = [
        'b', 'strong', 'i', 'em', 'u', 'br', 'p', 'span', 'ul', 'ol', 'li', 'small',
    ];

    /**
     * Elements whose content is as dangerous as their markup, so they go away
     * entirely.
     *
     * @var list<string>
     */
    public const array DROPPED_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template',
        'svg', 'math', 'form', 'input', 'button', 'select', 'textarea',
        'link', 'meta', 'base', 'title', 'head', 'frame', 'frameset', 'applet', 'img',
    ];

    public function sanitize(string $html): string
    {
        if (mb_trim($html) === '') {
            return '';
        }

        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);

        $body = $document->body;

        if ($body === null) {
            return '';
        }

        $this->clean($body);

        return $body->innerHTML;
    }

    private function clean(Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof Comment) {
                $child->remove();

                continue;
            }

            if (! $child instanceof Element) {
                continue;
            }

            $tag = mb_strtolower($child->localName);

            if (in_array($tag, self::DROPPED_WITH_CONTENT, true)) {
                $child->remove();

                continue;
            }

            $this->clean($child);

            if (in_array($tag, self::ALLOWED_TAGS, true) && $child->namespaceURI === 'http://www.w3.org/1999/xhtml') {
                $this->stripAttributes($child);

                continue;
            }

            $this->unwrap($child);
        }
    }

    private function stripAttributes(Element $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttributeNode($attribute);
        }
    }

    private function unwrap(Element $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $element->remove();
    }
}
