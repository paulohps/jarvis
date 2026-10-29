<?php

use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSyntaxException;

beforeEach(function () {
    $this->renderer = new TemplateRenderer;
});

test('renders variables and dotted paths', function () {
    $rendered = $this->renderer->renderText(
        'Invoice {% invoice.number %} for {% payer.name %} — {% current_month_name %}',
        [
            'current_month_name' => 'October',
            'invoice' => ['number' => '2026-0042'],
            'payer' => ['name' => 'Acme Inc.'],
        ],
    );

    expect($rendered)->toBe('Invoice 2026-0042 for Acme Inc. — October');
});

test('renders if else and comparisons', function () {
    $template = '{% if payer.name %}Hi {% payer.name %}{% else %}Hi there{% endif %}. '
        .'{% if invoice.currency == "BRL" %}Pix{% else %}Wire{% endif %} / '
        .'{% if invoice.currency != "BRL" %}abroad{% else %}local{% endif %}';

    expect($this->renderer->renderText($template, ['payer' => ['name' => 'Ana'], 'invoice' => ['currency' => 'BRL']]))
        ->toBe('Hi Ana. Pix / local')
        ->and($this->renderer->renderText($template, ['payer' => ['name' => ''], 'invoice' => ['currency' => 'USD']]))
        ->toBe('Hi there. Wire / abroad');
});

test('for loops are capped at 500 iterations', function () {
    $items = array_map(static fn (int $index): array => ['name' => 'x'], range(1, 600));

    $rendered = $this->renderer->renderText('{% for item in items %}{% item.name %}{% endfor %}', ['items' => $items]);

    expect(TemplateRenderer::MAX_LOOP_ITERATIONS)->toBe(500)
        ->and(mb_strlen($rendered))->toBe(500);
});

test('double braces are literal text', function () {
    expect($this->renderer->renderText('Hello {{ payer.name }}', ['payer' => ['name' => 'Ana']]))
        ->toBe('Hello {{ payer.name }}');
});

test('interpolated values are html escaped', function () {
    $context = ['payer' => ['name' => '<script>alert(1)</script> & "Co"']];

    expect($this->renderer->render('<p>{% payer.name %}</p>', $context))
        ->toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Co&quot;</p>')
        ->and($this->renderer->renderHtml('<p>{% payer.name %}</p>', $context))
        ->not->toContain('<script>');
});

test('body html is sanitized', function () {
    $html = $this->renderer->renderHtml(
        '<p onclick="steal()">Hi <strong>{% payer.name %}</strong></p><script>alert(1)</script><a href="javascript:alert(1)">link</a>',
        ['payer' => ['name' => 'Ana']],
    );

    expect($html)
        ->toBe('<p>Hi <strong>Ana</strong></p>link')
        ->not->toContain('script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:');
});

test('unclosed tag raises syntax error with position', function () {
    try {
        $this->renderer->render("Hello\nDear {% payer.name", ['payer' => ['name' => 'Ana']]);
        $this->fail('The unclosed tag was rendered.');
    } catch (TemplateSyntaxException $exception) {
        expect($exception->error->offset)->toBe(11)
            ->and($exception->error->line)->toBe(2)
            ->and($exception->error->column)->toBe(6)
            ->and($exception->getMessage())->toBe('Line 2, column 6: Unclosed "{%" tag.');
    }
});
