<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoices\InvoicePdf;
use App\Services\Invoices\InvoiceTemplateSchema;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateRenderer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\App;

/**
 * The e-mail that delivers an invoice to its recipients (US-6.5; Workflow 8):
 * the subject (plain text) and body (sanitized HTML) templates of the
 * invoice rendered with InvoiceTemplateSchema in the language picked for the
 * invoice (not the sender's own) and on the sender's timezone, and the
 * stored PDF attached as `invoice-{number}.pdf`. Replies go to the issuer.
 *
 * The sender is whoever owns the invoice: the creator of its recurrence, or
 * of the invoice when it is a one-off. A body written without block tags
 * keeps its line breaks.
 */
class InvoiceMail extends Mailable
{
    public const string VIEW = 'mail.invoice';

    /**
     * @var array{subject: string, body: string}|null
     */
    private ?array $rendered = null;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->invoice->issuer_email, $this->invoice->issuer_name)],
            subject: $this->rendered()['subject'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: self::VIEW,
            with: ['body' => $this->rendered()['body']],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk(InvoicePdf::disk(), (string) $this->invoice->pdf_path)
                ->as(self::attachmentName($this->invoice))
                ->withMime('application/pdf'),
        ];
    }

    public static function attachmentName(Invoice $invoice): string
    {
        return "invoice-{$invoice->number}.pdf";
    }

    /**
     * The language and timezone the e-mail is written in: the language picked
     * for the invoice, on the sender's calendar.
     */
    public static function contextBuilderFor(Invoice $invoice): TemplateContextBuilder
    {
        $sender = $invoice->recurrence->createdBy ?? $invoice->createdBy;

        return new TemplateContextBuilder(
            $invoice->locale->slug,
            ($sender instanceof User ? $sender->timezone : null) ?: 'UTC',
        );
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function rendered(): array
    {
        if ($this->rendered !== null) {
            return $this->rendered;
        }

        $builder = self::contextBuilderFor($this->invoice);
        $renderer = app(TemplateRenderer::class);
        $previousLocale = App::getLocale();

        App::setLocale($builder->locale);

        try {
            $context = (new InvoiceTemplateSchema)->context($this->invoice, $builder);
            $body = $renderer->renderHtml($this->invoice->email_body, $context);

            return $this->rendered = [
                'subject' => $renderer->renderText($this->invoice->email_subject, $context),
                'body' => preg_match('/<(p|br|div|ul|ol|h[1-6]|table)\b/i', $body) === 1 ? $body : nl2br($body),
            ];
        } finally {
            App::setLocale($previousLocale);
        }
    }
}
