<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Issues an invoice without a recurrence (US-6.7) from the same fields as a
 * recurrence (InvoiceFieldRules), with the date rules applied to the
 * current date on the user's calendar. It is issued in the optional
 * `workspace` of the input (a friendly id), else the user's default for the
 * invoices module (ResolvesWorkspace), with a payer and an issuer of that
 * workspace, and takes the workspace's next number of the month.
 *
 * In `automatic` mode it is sent right away on behalf of the user; in
 * `requires_review` mode it waits in `pending_review` for approval.
 */
final readonly class CreateOneOffInvoice
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    public function __construct(
        private GenerateInvoice $generateInvoice,
        private SendInvoice $sendInvoice,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input): Invoice
    {
        $this->authorizeInvoices($actor);

        $workspace = $this->workspaceFor($actor, $input, Module::INVOICES);
        $input = InvoiceFieldRules::normalize($input);

        /** @var array{payer: string, issuer: string, recipients: list<string>, email_subject: string, email_body: string, service_description: string, currency: string, amount: int|float|string, invoice_date_rule: string, invoice_date_day?: int|string|null, invoice_due_date_rule: string, due_date_value?: int|string|null, delivery_mode: string, mail_service?: string|null, locale?: string|null} $validated */
        $validated = Validator::make($input, InvoiceFieldRules::rules($input, $workspace, $actor), attributes: InvoiceFieldRules::attributes($input))->validate();

        $invoice = ($this->generateInvoice)(
            [...InvoiceFieldRules::columns($validated), 'workspace_id' => $workspace->id, 'recipients' => $validated['recipients']],
            now()->toImmutable(),
            null,
            $actor,
        );

        if ($invoice->isDraft()) {
            ($this->sendInvoice)($invoice, $actor);
        }

        return $invoice;
    }
}
