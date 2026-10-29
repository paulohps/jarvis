<?php

namespace App\Actions\Invoices;

use App\Actions\Recurrences\SaveRecurrence;
use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\InvoiceRecurrence;
use App\Models\Module;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits an invoice recurrence (US-6.3; Workflow 7): what each
 * generated invoice bills and how it is sent, plus its schedule.
 *
 * - `name` (up to 100 characters) and the fields of InvoiceFieldRules;
 * - `workspace` (creating only, optional): the friendly id of one of the
 *   actor's workspaces with the invoices module, else their default for it
 *   (ResolvesWorkspace). The workspace is fixed once created: editing reads
 *   no `workspace`. The payer and issuer must be of that workspace;
 * - `recurrence`: the input of SaveRecurrence, whose errors come back under
 *   `recurrence.*`.
 *
 * Editing a recurrence of a workspace the actor does not see is not found.
 *
 * Everything is saved in one transaction, so a refused schedule saves
 * nothing. Saving keeps a paused recurrence paused.
 */
final readonly class SaveInvoiceRecurrence
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    public const int MAX_RECIPIENTS = InvoiceFieldRules::MAX_RECIPIENTS;

    public function __construct(private SaveRecurrence $saveRecurrence) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the recurrence is of a workspace the actor does not see
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input, ?InvoiceRecurrence $invoiceRecurrence = null): InvoiceRecurrence
    {
        $this->authorizeInvoices($actor);

        if ($invoiceRecurrence !== null) {
            $this->authorizeWorkspaceRecord($actor, $invoiceRecurrence);
        }

        $workspace = $invoiceRecurrence?->workspace ?? $this->workspaceFor($actor, $input, Module::INVOICES);
        $input = InvoiceFieldRules::normalize($input);

        /** @var array{name: string, payer: string, issuer: string, recipients: list<string>, email_subject: string, email_body: string, service_description: string, currency: string, amount: int|float|string, invoice_date_rule: string, invoice_date_day?: int|string|null, invoice_due_date_rule: string, due_date_value?: int|string|null, delivery_mode: string, mail_service?: string|null, locale?: string|null} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            ...InvoiceFieldRules::rules($input, $workspace, $actor, $invoiceRecurrence?->mail_service_id),
            'recurrence' => ['required', 'array'],
        ], attributes: [
            'name' => __('name'),
            ...InvoiceFieldRules::attributes($input),
        ])->validate();

        /** @var array<string, mixed> $recurrenceInput */
        $recurrenceInput = $input['recurrence'];

        return DB::transaction(function () use ($actor, $workspace, $validated, $recurrenceInput, $invoiceRecurrence): InvoiceRecurrence {
            $invoiceRecurrence ??= new InvoiceRecurrence(['workspace_id' => $workspace->id, 'created_by_id' => $actor->id]);

            $invoiceRecurrence->fill([
                'name' => $validated['name'],
                ...InvoiceFieldRules::columns($validated),
            ])->save();

            $this->syncRecipients($invoiceRecurrence, $validated['recipients']);

            try {
                ($this->saveRecurrence)($invoiceRecurrence, $recurrenceInput);
            } catch (ValidationException $exception) {
                $errors = [];

                foreach ($exception->errors() as $field => $messages) {
                    $errors["recurrence.{$field}"] = $messages;
                }

                throw ValidationException::withMessages($errors);
            }

            return $invoiceRecurrence->unsetRelation('recurrence')->unsetRelation('recipients');
        });
    }

    /**
     * Keeps the addresses still listed, adds the new ones and drops the rest.
     *
     * @param  list<string>  $emails
     */
    private function syncRecipients(InvoiceRecurrence $invoiceRecurrence, array $emails): void
    {
        $invoiceRecurrence->recipients()->whereNotIn('email', $emails)->delete();

        $existing = $invoiceRecurrence->recipients()->pluck('email')->all();

        foreach ($emails as $email) {
            if (! in_array($email, $existing, true)) {
                $invoiceRecurrence->recipients()->create(['email' => $email]);
            }
        }
    }
}
