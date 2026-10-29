<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoices\InvoicePdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edits an invoice before it is sent (US-6.6): its description, issue and
 * due dates, amount and recipients, then renders its PDF again. The number
 * stays the one it was issued with.
 *
 * Only an invoice in `draft` or `pending_review` that is not being sent may
 * change; anything else is refused under `invoice`.
 *
 * - `service_description`: required (multiline);
 * - `issue_date` and `due_date`: `Y-m-d`, the due date not before the issue;
 * - `amount`: above zero with at most 2 decimals;
 * - `recipients`: 1 to 10 valid e-mail addresses, without repeats.
 */
final readonly class UpdatePendingInvoice
{
    use AuthorizesInvoicesModule;
    use GuardsInvoiceStatus;
    use ResolvesWorkspace;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the invoice is of a workspace the actor does not see
     * @throws ValidationException
     */
    public function __invoke(User $actor, Invoice $invoice, array $input): Invoice
    {
        $this->authorizeInvoices($actor);
        $this->authorizeWorkspaceRecord($actor, $invoice);
        $this->ensureAwaitingSend($invoice);

        $input = InvoiceInput::trim($input, ['service_description', 'issue_date', 'due_date', 'amount']);

        if (is_array($input['recipients'] ?? null)) {
            $input['recipients'] = array_values(array_filter(
                array_map(static fn (mixed $email): mixed => is_string($email) ? trim($email) : $email, $input['recipients']),
                static fn (mixed $email): bool => $email !== '',
            ));
        }

        /** @var array{service_description: string, issue_date: string, due_date: string, amount: int|float|string, recipients: list<string>} $validated */
        $validated = Validator::make($input, [
            'service_description' => ['required', 'string', 'max:5000'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'recipients' => ['required', 'array', 'min:1', 'max:'.SaveInvoiceRecurrence::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'string', 'email', 'max:255', 'distinct:ignore_case'],
        ], attributes: [
            'service_description' => __('description'),
            'issue_date' => __('invoice date'),
            'due_date' => __('due date'),
            'amount' => __('amount'),
            'recipients' => __('recipients'),
            'recipients.*' => __('recipient'),
        ])->validate();

        return DB::transaction(function () use ($invoice, $validated): Invoice {
            $invoice = $this->lockAwaitingSend($invoice);

            $invoice->fill([
                'service_description' => $validated['service_description'],
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'amount' => number_format((float) $validated['amount'], 2, '.', ''),
            ])->save();

            $invoice->recipients()->whereNotIn('email', $validated['recipients'])->delete();
            $existing = $invoice->recipients()->pluck('email')->all();

            foreach ($validated['recipients'] as $email) {
                if (! in_array($email, $existing, true)) {
                    $invoice->recipients()->create(['email' => $email]);
                }
            }

            $invoice->forceFill(['pdf_path' => InvoicePdf::store($invoice)])->save();

            return $invoice->unsetRelation('recipients');
        });
    }
}
