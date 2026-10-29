<?php

use App\Models\Currency;
use App\Models\Invoice;
use App\Services\Invoices\InvoicePdf;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\App;
use Spatie\LaravelPdf\Facades\Pdf;

test('pdf view renders all sections of the sample invoice', function () {
    $this->seed(LookupSeeder::class);
    Pdf::fake();

    $description = "Software development services as agreed.\nDocumentation of the delivered systems.";

    // The sample invoice, with the new number format.
    $invoice = Invoice::factory()->oneOff()->sent()->create([
        'number_year_month' => '202609',
        'number_sequence' => 1,
        'number' => '202609-001',
        'issue_date' => '2026-09-01',
        'due_date' => '2026-09-30',
        'currency_id' => Currency::idFor(Currency::USD),
        'amount' => '2000.00',
        'service_description' => $description,
        'payer_name' => 'Initech Corp',
        'payer_address' => "PO Box 1234\nSpringfield, IL 62701",
        'issuer_name' => 'Jane Doe Consulting Ltda',
        'issuer_email' => 'billing@janedoe.example',
        'issuer_postal_code' => '01310-100',
        'issuer_street' => 'Av. Paulista, 1000',
        'issuer_district' => 'Bela Vista',
        'issuer_city' => 'São Paulo',
        'issuer_state' => 'SP',
    ]);

    // The labels stay in English whatever the reader's language.
    App::setLocale('pt_BR');

    $pdf = InvoicePdf::for($invoice);
    $html = $pdf->getHtml();

    // Rendered by the PDF driver (faked here) from the `invoices.pdf` view.
    expect($pdf->base64())->not->toBeEmpty();
    Pdf::assertViewIs(InvoicePdf::VIEW);

    expect($html)->toContain('<html lang="en">')
        ->and($pdf->downloadName)->toBe('invoice-202609-001.pdf')
        ->and($html)->not->toContain('PREVIEW')
        ->not->toContain('Dólar americano')
        ->not->toContain('Vencimento');

    $this->assertStringContainsString('class="title">Invoice</h1>', $html);

    // Every block of the sample, in its order.
    expect(stringsInOrder($html, [
        'Invoice</h1>',
        'Receiver information',
        'Jane Doe Consulting Ltda',
        'billing@janedoe.example',
        '01310-100',
        'São Paulo, SP',
        'Av. Paulista, 1000',
        'Bela Vista',
        'Bill to',
        'Initech Corp',
        "PO Box 1234\nSpringfield, IL 62701",
        'Invoice</h2>',
        'Invoice number', 'Date', 'Due date',
        '202609-001', '01/09/2026', '30/09/2026',
        'Description',
        e($description),
        'American dollar',
        '2,000.00',
    ]))->toBeTrue();

    // Values are escaped.
    $invoice->payer_name = '<script>alert(1)</script>';

    expect(InvoicePdf::for($invoice)->getHtml())->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;');
});

/**
 * Whether every needle is in the haystack, each after the previous one.
 *
 * @param  list<string>  $needles
 */
function stringsInOrder(string $haystack, array $needles): bool
{
    $offset = 0;

    foreach ($needles as $needle) {
        $position = strpos($haystack, $needle, $offset);

        if ($position === false) {
            dump("Missing after offset {$offset}: {$needle}");

            return false;
        }

        $offset = $position + strlen($needle);
    }

    return true;
}
