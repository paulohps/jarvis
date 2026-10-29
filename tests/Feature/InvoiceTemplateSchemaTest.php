<?php

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Locale;
use App\Models\User;
use App\Rules\ValidTemplate;
use App\Services\Invoices\InvoiceTemplateSchema;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateRenderer;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('invoice context formats amount and dates', function () {
    $invoice = Invoice::factory()->oneOff()->create([
        'number_year_month' => '202609',
        'number_sequence' => 1,
        'number' => '202609-001',
        'issue_date' => '2026-09-01',
        'due_date' => '2026-09-30',
        'currency_id' => Currency::idFor(Currency::USD),
        'amount' => '2000',
        'payer_name' => 'Initech Corp',
        'issuer_name' => 'Jane Doe Consulting Ltda',
    ]);
    $schema = new InvoiceTemplateSchema;

    $english = $schema->context($invoice, new TemplateContextBuilder('en', 'America/Sao_Paulo'));

    expect($english['invoice'])->toBe([
        'number' => '202609-001',
        'date' => '01/09/2026',
        'due_date' => '30/09/2026',
        'amount' => '2,000.00',
        'currency' => 'American dollar',
    ])
        ->and($english['payer'])->toBe(['name' => 'Initech Corp'])
        ->and($english['issuer'])->toBe(['name' => 'Jane Doe Consulting Ltda'])
        // The month is the invoice's, on the reader's calendar: never the day before in São Paulo.
        ->and($english['current_month_name'])->toBe('September')
        ->and($english['current_year'])->toBe(2026);

    $reader = User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR), 'timezone' => 'America/Sao_Paulo']);
    $brazilian = $schema->context($invoice, TemplateContextBuilder::forUser($reader));

    // In the reader's language, whatever the language of the request.
    expect(app()->getLocale())->toBe('en')
        ->and($brazilian['invoice']['currency'])->toBe('Dólar americano')
        ->and($brazilian['invoice']['date'])->toBe('01/09/2026')
        ->and($brazilian['invoice']['amount'])->toBe('2,000.00')
        ->and($brazilian['current_month_name'])->toBe('setembro');

    // The subject of the sample configuration renders with it.
    $subject = app(TemplateRenderer::class)->renderText(
        'Invoice {% invoice.number %} — {% current_month_name %} {% current_year %}: {% invoice.currency %} {% invoice.amount %}, due {% invoice.due_date %}',
        $english,
    );

    expect($subject)->toBe('Invoice 202609-001 — September 2026: American dollar 2,000.00, due 30/09/2026');

    // Every declared variable is in the context, and only those validate.
    foreach (array_keys($schema->all()) as $path) {
        expect(data_get($english, $path))->not->toBeNull();
    }

    expect(Validator::make(['subject' => '{% payer.name %} {% issuer.name %} {% invoice.date %}'], ['subject' => [new ValidTemplate($schema)]])->passes())->toBeTrue()
        ->and(Validator::make(['subject' => '{% invoice.total %}'], ['subject' => [new ValidTemplate($schema)]])->passes())->toBeFalse();
});
