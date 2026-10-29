<?php

use App\Models\Locale;
use App\Rules\ValidTemplate;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->schema = new class extends TemplateSchema
    {
        public function variables(): array
        {
            return [
                ...TemplateContextBuilder::commonVariables(),
                TemplateVariable::text('invoice.number', 'Invoice number', '2026-0042'),
                TemplateVariable::text('invoice.currency', 'Currency', 'BRL', ['BRL', 'USD']),
                TemplateVariable::collection('invoice.items', 'Items', [
                    TemplateVariable::text('name', 'Name'),
                ]),
            ];
        }
    };

    $this->validate = fn (mixed $body): Illuminate\Validation\Validator => Validator::make(
        ['body' => $body],
        ['body' => ['required', new ValidTemplate($this->schema)]],
    );
});

test('template with unknown variable fails validation', function () {
    $validator = ($this->validate)("Hello\n  {% payer.name %}");

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->get('body'))->toBe(['Line 2, column 3: Unknown variable "payer.name".']);
});

test('template with unclosed tag fails validation', function () {
    $validator = ($this->validate)('Invoice {% invoice.number');

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('body'))->toBe('Line 1, column 9: Unclosed "{%" tag.');
});

test('looping over something that is not a list fails validation', function () {
    $validator = ($this->validate)('{% for item in invoice.number %}{% endfor %}{% for item in nothing %}{% endfor %}');

    expect($validator->errors()->get('body'))->toBe([
        'Line 1, column 1: "invoice.number" is a single value and cannot be looped over.',
        'Line 1, column 45: Unknown list "nothing".',
    ]);
});

test('validation errors are written in the reader language', function () {
    App::setLocale(Locale::PT_BR);

    expect(($this->validate)('{% payer.name %}')->errors()->first('body'))
        ->toBe('Linha 1, coluna 1: Variável desconhecida "payer.name".');
});

test('valid template passes validation', function () {
    $template = <<<'TEMPLATE'
        Invoice {% invoice.number %} — {% current_month_name %}/{% current_year %}
        {% if invoice.currency == "BRL" %}Pix{% else %}Wire{% endif %}
        {% for item in invoice.items %}- {% item.name %}
        {% endfor %}{{ literal }}
        TEMPLATE;

    expect(($this->validate)($template)->passes())->toBeTrue();
});

test('a value that is not text fails validation', function () {
    expect(($this->validate)(['{% invoice.number %}'])->errors()->first('body'))
        ->toBe('The body field must be a string.');
});
