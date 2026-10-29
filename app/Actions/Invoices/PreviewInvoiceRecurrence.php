<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Mail\InvoiceMail;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceRecurrenceRecipient;
use App\Models\User;
use App\Services\Invoices\InvoiceDateCalculator;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoicePreview;
use App\Services\Invoices\InvoiceTemplateSchema;
use App\Services\Recurrences\RecurrenceCalculator;
use App\Services\Templates\TemplateError;
use App\Services\Templates\TemplateFormat;
use App\Services\Templates\TemplatePreview;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;

/**
 * Shows what the next run of an invoice recurrence would issue and send
 * (US-6.4): the next run of its schedule from now (as if it were running,
 * even when paused), the dates its rules give for that run, the provisional
 * number `YYYYMM-PREVIEW` of the issue month, the e-mail subject (plain text)
 * and body (sanitized HTML) in the language picked for the recurrence, as
 * InvoiceMail writes them, and the PDF marked "PREVIEW".
 *
 * Nothing is stored or sent, and the number sequence is never touched. The
 * recurrence may be unsaved (the form being filled in) as long as its
 * payer, issuer, currency, rules, recipients and schedule relations are set.
 * A schedule without a run left previews today's run. A template that saving
 * would refuse shows its problems instead of a rendering.
 */
final readonly class PreviewInvoiceRecurrence
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    public const string NUMBER_SUFFIX = 'PREVIEW';

    public function __construct(
        private RecurrenceCalculator $calculator,
        private TemplateRenderer $renderer,
        private TemplateValidator $validator,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the recurrence is of a workspace the actor does not see
     */
    public function __invoke(User $actor, InvoiceRecurrence $invoiceRecurrence): InvoicePreview
    {
        $this->authorizeInvoices($actor);

        if ($invoiceRecurrence->exists || $invoiceRecurrence->workspace_id !== null) {
            $this->authorizeWorkspaceRecord($actor, $invoiceRecurrence);
        }

        $now = now()->toImmutable();
        $schedule = $invoiceRecurrence->recurrence;
        $runAt = $schedule === null ? null : $this->calculator->nextRunAfter($schedule, $now);

        $dates = InvoiceDateCalculator::for($invoiceRecurrence, $runAt ?? $now);
        $invoice = $invoiceRecurrence->newInvoice($dates, $dates->issueDate->format('Ym').'-'.self::NUMBER_SUFFIX, 0);

        $schema = new InvoiceTemplateSchema;
        $builder = InvoiceMail::contextBuilderFor($invoice);
        $previousLocale = App::getLocale();

        App::setLocale($builder->locale);

        try {
            $context = $schema->context($invoice, $builder);
            $subject = $this->render($invoice->email_subject, $schema, $context, TemplateFormat::Text);
            $body = $this->render($invoice->email_body, $schema, $context, TemplateFormat::Html);
        } finally {
            App::setLocale($previousLocale);
        }

        return new InvoicePreview(
            $runAt,
            $dates,
            $invoice,
            array_values($invoiceRecurrence->recipients->map(fn (InvoiceRecurrenceRecipient $recipient): string => $recipient->email)->all()),
            $subject,
            $body,
            InvoicePdf::for($invoice, isPreview: true),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function render(string $template, InvoiceTemplateSchema $schema, array $context, TemplateFormat $format): TemplatePreview
    {
        $errors = $this->validator->validate($template, $schema);

        if ($errors !== []) {
            return new TemplatePreview(null, $format, array_map(static fn (TemplateError $error): string => $error->translated(), $errors));
        }

        return new TemplatePreview(
            $format === TemplateFormat::Html ? $this->renderer->renderHtml($template, $context) : $this->renderer->renderText($template, $context),
            $format,
        );
    }
}
