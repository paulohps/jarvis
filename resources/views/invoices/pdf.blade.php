{{--
    The invoice PDF, in the layout of the original sample invoice:
    the blue "Invoice" title, "Receiver information" (the issuer), "Bill to"
    (the payer), the number / date / due date in three columns, the
    description, then the currency spelled out and the amount.

    Always in English, like the sample: the labels are fixed text, not
    translations. Data: see App\Services\Invoices\InvoicePdf::viewData().
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        {!! file_get_contents(resource_path('css/invoice-pdf.css')) !!}
    </style>
</head>
<body>
    @if ($isPreview)
        <div class="preview-watermark" aria-hidden="true">PREVIEW</div>
        <p class="preview-banner" data-invoice-preview>PREVIEW — not a valid invoice; the number is provisional.</p>
    @endif

    <h1 class="title">Invoice</h1>

    <section class="section" data-invoice-section="receiver">
        <h2 class="section-title">Receiver information</h2>

        <p class="line">{{ $invoice->issuer_name }}</p>
        <p class="line">{{ $invoice->issuer_email }}</p>

        <div class="pair">
            <div>{{ $invoice->issuer_postal_code }}</div>
            <div>{{ $invoice->issuer_city }}, {{ $invoice->issuer_state }}</div>
        </div>

        <div class="pair">
            <div>{{ $invoice->issuer_street }}</div>
            <div class="value">{{ $invoice->issuer_district }}</div>
        </div>
    </section>

    <section class="section bill-to" data-invoice-section="bill-to">
        <h2 class="section-title">Bill to</h2>

        <p class="line">{{ $invoice->payer_name }}</p>
        <p class="line multiline">{{ $invoice->payer_address }}</p>
    </section>

    <section class="section" data-invoice-section="invoice">
        <h2 class="section-title">Invoice</h2>

        <table class="columns">
            <thead>
                <tr>
                    <th scope="col">Invoice number</th>
                    <th scope="col">Date</th>
                    <th scope="col">Due date</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="value" data-invoice-number>{{ $invoice->number }}</td>
                    <td class="value" data-invoice-date>{{ $issueDate }}</td>
                    <td class="value" data-invoice-due-date>{{ $dueDate }}</td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="section" data-invoice-section="description">
        <h2 class="section-title">Description</h2>

        <p class="description">{{ $invoice->service_description }}</p>
    </section>

    <section class="total" data-invoice-section="total">
        <p class="currency" data-invoice-currency>{{ $currencyName }}</p>
        <p class="amount" data-invoice-amount>{{ $amount }}</p>
    </section>
</body>
</html>
