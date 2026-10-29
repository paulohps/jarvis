<?php

use App\Actions\Templates\PreviewTemplate;
use App\Models\Locale;
use App\Models\User;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateFormat;
use App\Services\Templates\TemplatePreview;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\App;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->schema = new class extends TemplateSchema
    {
        public function variables(): array
        {
            return [
                ...TemplateContextBuilder::commonVariables(),
                TemplateVariable::text('payer.name', 'Payer name', 'Acme <Inc>'),
                TemplateVariable::date('invoice.due_date', 'Due date', '2026-10-10'),
                TemplateVariable::money('invoice.amount', 'Amount', '2000'),
            ];
        }
    };

    $this->preview = app(PreviewTemplate::class);
});

test('preview renders sample data in the user locale', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 01:00', 'UTC'));

    $user = User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR), 'timezone' => 'America/Sao_Paulo']);

    $preview = ($this->preview)(
        $this->schema,
        'Fatura de {% current_month_name %}/{% current_year %} para {% payer.name %}: {% invoice.amount %}, vence em {% invoice.due_date %}',
        $user,
    );

    expect($preview->isValid())->toBeTrue()
        ->and($preview->output)->toBe('Fatura de outubro/2026 para Acme <Inc>: 2,000.00, vence em 10 de outubro de 2026')
        ->and(App::getLocale())->toBe('en');
});

test('preview of plain text contexts strips html', function () {
    $user = User::factory()->create();
    $template = '<p onclick="x()">Hi <strong>{% payer.name %}</strong></p><script>alert(1)</script>';

    $text = ($this->preview)($this->schema, $template, $user, TemplateFormat::Text);
    $html = ($this->preview)($this->schema, $template, $user, TemplateFormat::Html);

    expect($text->output)->toBe('Hi Acme <Inc>alert(1)')
        ->and($html->output)->toBe('<p>Hi <strong>Acme &lt;Inc&gt;</strong></p>');
});

test('an invalid template shows its errors in the user language instead of the preview', function () {
    $user = User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR)]);

    $preview = ($this->preview)($this->schema, "Oi\n{% invoice.number %} {% if", $user);

    expect($preview->isValid())->toBeFalse()
        ->and($preview->output)->toBeNull()
        ->and($preview->errors)->toBe(['Linha 2, coluna 22: Tag "{%" sem fechamento.']);

    $this->blade('<x-ui.template-preview :preview="$preview" />', ['preview' => $preview])
        ->assertSee('role="alert"', false)
        ->assertSee('Linha 2, coluna 22')
        ->assertDontSee('data-format', false);
});

test('the preview component shows html bodies as markup and text as text', function () {
    $this->blade('<x-ui.template-preview :preview="$preview" />', [
        'preview' => new TemplatePreview('<p>Hi <strong>Ana</strong></p>', TemplateFormat::Html),
    ])->assertSee('<p>Hi <strong>Ana</strong></p>', false)->assertSee('data-format="html"', false);

    $this->blade('<x-ui.template-preview :preview="$preview" label="Title" />', [
        'preview' => new TemplatePreview('Hi <Ana>', TemplateFormat::Text),
    ])->assertSee('Hi &lt;Ana&gt;', false)->assertSee('data-format="text"', false)->assertSee('Title');
});
