<?php

namespace App\Livewire\Invoices;

use App\Actions\Invoices\DeleteInvoiceRecurrence;
use App\Actions\Invoices\GenerateAndSendNow;
use App\Actions\Invoices\PreviewInvoiceRecurrence;
use App\Actions\Invoices\SaveInvoiceRecurrence;
use App\Actions\Recurrences\PauseRecurrence;
use App\Actions\Recurrences\ResumeRecurrence;
use App\Livewire\Forms\RecurrencePicker;
use App\Models\Currency;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceRecurrenceRecipient;
use App\Models\Issuer;
use App\Models\Locale;
use App\Models\MailService;
use App\Models\Module;
use App\Models\Payer;
use App\Models\RecurrenceFrequency;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Invoices\InvoicePreview;
use App\Services\Invoices\InvoiceTemplateSchema;
use App\Support\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Creates or edits an invoice recurrence (US-6.3; Workflow 7): payer and
 * issuer, recipients, the e-mail subject and body in `x-ui.template-editor`,
 * the language the e-mail is written in, the service description, currency
 * and amount, the date rules, the sending mode and the schedule in the recurrence picker.
 *
 * An existing recurrence also shows its next run in the reader's timezone,
 * can be paused, resumed and deleted, and can issue and send an invoice
 * right away ("Send now", US-6.7) without moving its next run. The fields are named after the input of
 * SaveInvoiceRecurrence, so its errors show next to them.
 *
 * "Preview" shows the next run of the form as it is filled in, saved or not
 * (PreviewInvoiceRecurrence): its dates, the provisional number, the e-mail
 * and the PDF marked "PREVIEW". It follows the fields until it is closed.
 *
 * A new recurrence goes to the workspace picked — offered only to a user
 * with the module in several, their default one preselected — and its payer
 * and issuer are picked among those of that workspace. The workspace of an
 * existing recurrence is fixed and only shown; one of a workspace the user
 * does not see is not found.
 *
 * @property-read InvoiceRecurrence|null $invoiceRecurrence
 * @property-read InvoiceTemplateSchema $schema
 * @property-read Collection<int, Payer> $payers
 * @property-read Collection<int, Issuer> $issuers
 * @property-read Collection<int, Currency> $currencies
 * @property-read Collection<int, MailService> $mailServices
 * @property-read InvoicePreview|null $invoicePreview
 */
#[Layout('layouts::app-shell')]
class InvoiceRecurrenceForm extends Component
{
    use InteractsWithInvoiceWorkspaces;

    public const string DEFAULT_SUBJECT = 'Invoice {% invoice.number %} — {% current_month_name %} {% current_year %}';

    public const string DEFAULT_BODY = "Hello,\n\nPlease find attached the invoice {% invoice.number %} of {% invoice.currency %} {% invoice.amount %}, due on {% invoice.due_date %}.\n\nBest regards,\n{% issuer.name %}";

    /**
     * The friendly id of the recurrence being edited; null while creating.
     */
    #[Locked]
    public ?string $recurrenceId = null;

    /**
     * The workspace (friendly id) of a new recurrence; the recurrence's own
     * while editing, where it cannot change.
     */
    public string $workspace = '';

    public string $name = '';

    public string $payer = '';

    public string $issuer = '';

    /**
     * @var list<string>
     */
    public array $recipients = [''];

    public string $email_subject = self::DEFAULT_SUBJECT;

    public string $email_body = self::DEFAULT_BODY;

    public string $service_description = '';

    public string $currency = Currency::USD;

    public string $amount = '';

    public string $invoice_date_rule = InvoiceDateRule::EXECUTION_DAY;

    public int|string|null $invoice_date_day = 1;

    public string $invoice_due_date_rule = InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH;

    public int|string|null $due_date_value = 10;

    public string $delivery_mode = InvoiceDeliveryMode::AUTOMATIC;

    /**
     * The friendly id of the mail service the invoices go through; empty for
     * the default mailer.
     */
    public string $mail_service = '';

    /**
     * The language the e-mail is written in (month names, dates, currency),
     * whatever the user's own.
     */
    public string $locale = Localization::DEFAULT_LOCALE;

    public RecurrencePicker $recurrence;

    public ?string $status = null;

    /**
     * Whether the preview of the next run is open.
     */
    public bool $showPreview = false;

    public function mount(?InvoiceRecurrence $invoiceRecurrence = null): void
    {
        if ($invoiceRecurrence === null || ! $invoiceRecurrence->exists) {
            $this->workspace = $this->defaultWorkspaceId();
            $this->recurrence->load($this->actor()->timezone);
            $this->recurrence->frequency = RecurrenceFrequency::MONTHLY;
            $this->recurrence->day_of_month = 1;
            $this->recurrence->time_of_day = '08:00';

            return;
        }

        abort_unless($invoiceRecurrence->isVisibleTo($this->actor()), 404);

        $this->recurrenceId = $invoiceRecurrence->friendly_id;
        $this->fillFrom($invoiceRecurrence);
    }

    #[Computed]
    public function invoiceRecurrence(): ?InvoiceRecurrence
    {
        return $this->recurrenceId === null
            ? null
            : InvoiceRecurrence::query()
                ->visibleTo($this->actor())
                ->with(['recurrence.frequency', 'recurrence.weekdays', 'workspace'])
                ->where('friendly_id', $this->recurrenceId)
                ->firstOrFail();
    }

    /**
     * The workspace the recurrence is in: its own while editing, else the
     * one picked among the user's (their default when the pick is not one of
     * them). Null when they have the module nowhere.
     */
    public function selectedWorkspace(): ?Workspace
    {
        if ($this->invoiceRecurrence !== null) {
            return $this->invoiceRecurrence->workspace;
        }

        $memberships = $this->actor()->membershipsWithModule(Module::INVOICES);

        return $memberships->first(fn (WorkspaceMembership $membership): bool => $membership->workspace->friendly_id === $this->workspace)?->workspace
            ?? $this->actor()->defaultWorkspaceFor(Module::INVOICES);
    }

    /**
     * Another workspace was picked for a new recurrence: the payer and issuer
     * of the previous one are cleared. An existing recurrence keeps its own.
     */
    public function updatedWorkspace(): void
    {
        if ($this->invoiceRecurrence !== null) {
            $this->workspace = $this->invoiceRecurrence->workspace->friendly_id;

            return;
        }

        $this->payer = '';
        $this->issuer = '';
        unset($this->payers, $this->issuers);
    }

    #[Computed]
    public function schema(): InvoiceTemplateSchema
    {
        return new InvoiceTemplateSchema;
    }

    /**
     * The payers of the recurrence's workspace to pick from; the current one
     * stays listed even if it was deleted since, so the form shows it (saving
     * then asks for another).
     *
     * @return Collection<int, Payer>
     */
    #[Computed]
    public function payers(): Collection
    {
        return Payer::query()
            ->withTrashed()
            ->where('workspace_id', $this->selectedWorkspace()?->id)
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('friendly_id', $this->payer))
            ->orderBy('name')
            ->get();
    }

    /**
     * The issuers to pick from, like `payers()`.
     *
     * @return Collection<int, Issuer>
     */
    #[Computed]
    public function issuers(): Collection
    {
        return Issuer::query()
            ->withTrashed()
            ->where('workspace_id', $this->selectedWorkspace()?->id)
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('friendly_id', $this->issuer))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Currency>
     */
    #[Computed]
    public function currencies(): Collection
    {
        return Currency::query()->active()->orderBy('slug')->get();
    }

    /**
     * @return array<string, string>
     */
    public function dateRuleOptions(): array
    {
        return [
            InvoiceDateRule::EXECUTION_DAY => __('Execution day'),
            InvoiceDateRule::DAY_OF_CURRENT_MONTH => __('Day of the current month'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function dueDateRuleOptions(): array
    {
        return [
            InvoiceDueDateRule::DAYS_AFTER_ISSUE => __('Days after the invoice date'),
            InvoiceDueDateRule::DAY_OF_NEXT_MONTH => __('Day of the next month'),
            InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH => __('Last day of the invoice month'),
        ];
    }

    /**
     * The languages an e-mail may be written in, each written in itself.
     *
     * @return array<string, string>
     */
    public function localeOptions(): array
    {
        return Localization::NAMES;
    }

    /**
     * @return array<string, string>
     */
    public function deliveryModeOptions(): array
    {
        return [
            InvoiceDeliveryMode::AUTOMATIC => __('Send automatically'),
            InvoiceDeliveryMode::REQUIRES_REVIEW => __('Review before sending'),
        ];
    }

    /**
     * The mail services the invoices may go through: the user's own, the
     * global ones and the one already picked.
     *
     * @return Collection<int, MailService>
     */
    #[Computed]
    public function mailServices(): Collection
    {
        $currentId = $this->invoiceRecurrence?->mail_service_id;

        return MailService::query()
            ->where(fn ($query) => $query->usableBy($this->actor())->when($currentId !== null, fn ($query) => $query->orWhere('id', $currentId)))
            ->orderBy('name')
            ->get();
    }

    /**
     * The next run of the form as it is filled in, while the preview is
     * open; null when it is closed or the form cannot make one yet.
     */
    #[Computed]
    public function invoicePreview(): ?InvoicePreview
    {
        if (! $this->showPreview) {
            return null;
        }

        try {
            $draft = $this->draftRecurrence();

            return $draft === null ? null : app(PreviewInvoiceRecurrence::class)($this->actor(), $draft);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function preview(): void
    {
        $this->showPreview = true;
        unset($this->invoicePreview);
    }

    public function closePreview(): void
    {
        $this->showPreview = false;
        unset($this->invoicePreview);
    }

    public function addRecipient(): void
    {
        if (count($this->recipients) < SaveInvoiceRecurrence::MAX_RECIPIENTS) {
            $this->recipients[] = '';
        }
    }

    public function removeRecipient(int $index): void
    {
        $recipients = $this->recipients;
        unset($recipients[$index]);
        $this->recipients = array_values($recipients);

        if ($this->recipients === []) {
            $this->recipients = [''];
        }
    }

    public function save(SaveInvoiceRecurrence $saveInvoiceRecurrence): void
    {
        $saved = $saveInvoiceRecurrence($this->actor(), $this->input(), $this->invoiceRecurrence);

        if ($this->recurrenceId === null) {
            session()->flash('invoice-recurrence-status', __('Recurrence :name saved.', ['name' => $saved->name]));

            $this->redirectRoute('invoices.recurrences.edit', $saved, navigate: true);

            return;
        }

        $this->status = __('Recurrence :name saved.', ['name' => $saved->name]);
        unset($this->invoiceRecurrence);
    }

    public function pause(PauseRecurrence $pauseRecurrence): void
    {
        $schedule = $this->invoiceRecurrence?->recurrence;

        if ($schedule !== null) {
            $pauseRecurrence($schedule);
            $this->status = __('Recurrence paused. Nothing runs until you resume it.');
        }

        unset($this->invoiceRecurrence);
    }

    public function resume(ResumeRecurrence $resumeRecurrence): void
    {
        $schedule = $this->invoiceRecurrence?->recurrence;

        if ($schedule !== null) {
            $resumeRecurrence($schedule);
            $this->status = __('Recurrence resumed. The runs missed while paused are skipped.');
        }

        unset($this->invoiceRecurrence);
    }

    public function delete(DeleteInvoiceRecurrence $deleteInvoiceRecurrence): void
    {
        if ($this->invoiceRecurrence === null) {
            return;
        }

        $deleteInvoiceRecurrence($this->actor(), $this->invoiceRecurrence);

        $this->redirectRoute('invoices.index', navigate: true);
    }

    /**
     * Issues an invoice of the saved recurrence for today and sends it, then
     * opens it.
     */
    public function sendNow(GenerateAndSendNow $generateAndSendNow): void
    {
        if ($this->invoiceRecurrence === null) {
            return;
        }

        $invoice = $generateAndSendNow($this->actor(), $this->invoiceRecurrence);

        $this->redirectRoute('invoices.history.show', $invoice, navigate: true);
    }

    /**
     * The input of SaveInvoiceRecurrence.
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return [
            'workspace' => $this->recurrenceId === null && $this->showsWorkspaces() ? $this->workspace : null,
            'name' => $this->name,
            'payer' => $this->payer,
            'issuer' => $this->issuer,
            'recipients' => array_values(array_filter($this->recipients, fn (string $email): bool => trim($email) !== '')),
            'email_subject' => $this->email_subject,
            'email_body' => $this->email_body,
            'service_description' => $this->service_description,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'invoice_date_rule' => $this->invoice_date_rule,
            'invoice_date_day' => $this->invoice_date_rule === InvoiceDateRule::DAY_OF_CURRENT_MONTH ? $this->invoice_date_day : null,
            'invoice_due_date_rule' => $this->invoice_due_date_rule,
            'due_date_value' => $this->invoice_due_date_rule === InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH ? null : $this->due_date_value,
            'delivery_mode' => $this->delivery_mode,
            'mail_service' => $this->mail_service === '' ? null : $this->mail_service,
            'locale' => $this->locale,
            'recurrence' => $this->recurrence->data(),
        ];
    }

    public function render(): View
    {
        $this->status ??= session('invoice-recurrence-status');

        return view('livewire.invoices.invoice-recurrence-form')
            ->title($this->recurrenceId === null ? __('New invoice recurrence') : $this->name);
    }

    /**
     * The recurrence as filled in, unsaved, with every relation the preview
     * reads; null while the payer, issuer, currency or amount is missing.
     *
     * @throws InvalidArgumentException when a rule or the schedule cannot be read
     */
    private function draftRecurrence(): ?InvoiceRecurrence
    {
        $workspace = $this->selectedWorkspace();
        $payer = Payer::query()->withTrashed()->where('workspace_id', $workspace?->id)->where('friendly_id', $this->payer)->first();
        $issuer = Issuer::query()->withTrashed()->where('workspace_id', $workspace?->id)->where('friendly_id', $this->issuer)->first();
        $currency = Currency::query()->slug($this->currency)->first();

        if ($payer === null || $issuer === null || $currency === null || ! is_numeric($this->amount) || (float) $this->amount <= 0) {
            return null;
        }

        $dateRule = InvoiceDateRule::query()->slug($this->invoice_date_rule)->first();
        $dueDateRule = InvoiceDueDateRule::query()->slug($this->invoice_due_date_rule)->first();
        $schedule = $this->recurrence->draft();

        if ($dateRule === null || $dueDateRule === null || $schedule === null) {
            throw new InvalidArgumentException('The rules or the schedule are not valid.');
        }

        $input = $this->input();
        $draft = new InvoiceRecurrence([
            'workspace_id' => $workspace?->id,
            'name' => $this->name,
            'payer_id' => $payer->id,
            'issuer_id' => $issuer->id,
            'currency_id' => $currency->id,
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'service_description' => $this->service_description,
            'email_subject' => $this->email_subject,
            'email_body' => $this->email_body,
            'invoice_date_rule_id' => $dateRule->id,
            'invoice_date_day' => is_numeric($input['invoice_date_day']) ? (int) $input['invoice_date_day'] : null,
            'invoice_due_date_rule_id' => $dueDateRule->id,
            'due_date_value' => is_numeric($input['due_date_value']) ? (int) $input['due_date_value'] : null,
            'locale_id' => Locale::idFor(Localization::normalize($this->locale) ?? Localization::DEFAULT_LOCALE),
        ]);

        /** @var list<string> $recipients */
        $recipients = $input['recipients'];

        return $draft
            ->setRelation('payer', $payer)
            ->setRelation('issuer', $issuer)
            ->setRelation('currency', $currency)
            ->setRelation('dateRule', $dateRule)
            ->setRelation('dueDateRule', $dueDateRule)
            ->setRelation('createdBy', $this->invoiceRecurrence->createdBy ?? $this->actor())
            ->setRelation('recurrence', $schedule)
            ->setRelation('recipients', new Collection(array_map(
                fn (string $email): InvoiceRecurrenceRecipient => new InvoiceRecurrenceRecipient(['email' => $email]),
                $recipients,
            )));
    }

    private function fillFrom(InvoiceRecurrence $invoiceRecurrence): void
    {
        $invoiceRecurrence->loadMissing(['workspace', 'payer', 'issuer', 'currency', 'dateRule', 'dueDateRule', 'deliveryMode', 'mailService', 'locale', 'recipients', 'recurrence.frequency', 'recurrence.weekdays']);

        $this->workspace = $invoiceRecurrence->workspace->friendly_id;
        $this->name = $invoiceRecurrence->name;
        $this->payer = $invoiceRecurrence->payer->friendly_id;
        $this->issuer = $invoiceRecurrence->issuer->friendly_id;
        $this->recipients = array_values($invoiceRecurrence->recipients->sortBy('id')->pluck('email')->all()) ?: [''];
        $this->email_subject = $invoiceRecurrence->email_subject;
        $this->email_body = $invoiceRecurrence->email_body;
        $this->service_description = $invoiceRecurrence->service_description;
        $this->currency = $invoiceRecurrence->currency->slug;
        $this->amount = $invoiceRecurrence->amount;
        $this->invoice_date_rule = $invoiceRecurrence->dateRule->slug;
        $this->invoice_date_day = $invoiceRecurrence->invoice_date_day ?? 1;
        $this->invoice_due_date_rule = $invoiceRecurrence->dueDateRule->slug;
        $this->due_date_value = $invoiceRecurrence->due_date_value ?? 10;
        $this->delivery_mode = $invoiceRecurrence->deliveryMode->slug;
        $this->mail_service = (string) $invoiceRecurrence->mailService?->friendly_id;
        $this->locale = $invoiceRecurrence->locale->slug;
        $this->recurrence->load($invoiceRecurrence->ownerTimezone(), $invoiceRecurrence->recurrence);
    }
}
