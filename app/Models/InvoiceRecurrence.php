<?php

namespace App\Models;

use App\Actions\Invoices\GenerateInvoice;
use App\Actions\Invoices\SendInvoice;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasFriendlyId;
use App\Models\Concerns\HasRecurrence;
use App\Models\Contracts\Schedulable;
use App\Services\Invoices\InvoiceDates;
use App\Services\Invoices\InvoiceNotifier;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceRecurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The configuration that generates and sends invoices on its own (US-6.3).
 * `email_subject`/`email_body` are `{% %}` templates copied to each invoice.
 * It belongs to a workspace, fixed when it is created, and bills a payer
 * and an issuer of that workspace; its invoices are issued there.
 *
 * Registered in the morph map as a `recurrences.schedulable` owner. Soft
 * deleted owners are skipped by `Recurrence::due()`.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $friendly_id
 * @property string $name
 * @property int $payer_id
 * @property int $issuer_id
 * @property int $currency_id
 * @property string $amount
 * @property string $service_description
 * @property string $email_subject
 * @property string $email_body
 * @property int $invoice_date_rule_id
 * @property int|null $invoice_date_day
 * @property int $invoice_due_date_rule_id
 * @property int|null $due_date_value
 * @property int $invoice_delivery_mode_id
 * @property int|null $mail_service_id
 * @property int $locale_id
 * @property int $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Payer $payer
 * @property-read Issuer $issuer
 * @property-read Currency $currency
 * @property-read InvoiceDateRule $dateRule
 * @property-read InvoiceDueDateRule $dueDateRule
 * @property-read InvoiceDeliveryMode $deliveryMode
 * @property-read User $createdBy
 * @property-read MailService|null $mailService
 * @property-read Locale $locale
 * @property-read Collection<int, InvoiceRecurrenceRecipient> $recipients
 */
#[Fillable([
    'workspace_id', 'name', 'payer_id', 'issuer_id', 'currency_id', 'amount', 'service_description', 'email_subject', 'email_body',
    'invoice_date_rule_id', 'invoice_date_day', 'invoice_due_date_rule_id', 'due_date_value', 'invoice_delivery_mode_id',
    'mail_service_id', 'locale_id', 'created_by_id',
])]
class InvoiceRecurrence extends Model implements Schedulable
{
    /** @use HasFactory<InvoiceRecurrenceFactory> */
    use BelongsToWorkspace, HasFactory, HasFriendlyId, HasRecurrence, SoftDeletes;

    protected $table = 'invoice_recurrences';

    public static function idPrefix(): string
    {
        return 'irec';
    }

    public static function workspaceModule(): string
    {
        return Module::INVOICES;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'invoice_date_day' => 'integer',
            'due_date_value' => 'integer',
        ];
    }

    /**
     * Generates the invoice of the slot's period (US-6.5, US-6.6), on the
     * `invoices` queue: `YYYY-MM` for a monthly recurrence, the ISO date
     * otherwise, both on the owner's calendar. In `automatic` mode the
     * invoice is sent right away; in `requires_review` mode the members of its
     * workspace are told it waits for review. A slot run again (a retried job) finds the
     * invoice of its period and only finishes what is missing: it sends an
     * automatic invoice still in draft and never notifies twice.
     */
    public function runScheduledSlot(CarbonImmutable $slot): void
    {
        $invoice = app(GenerateInvoice::class)($this, $slot, $this->periodKeyFor($slot));

        if ($this->deliveryMode->slug === InvoiceDeliveryMode::AUTOMATIC) {
            if ($invoice->isDraft() && $invoice->deliveries()->doesntExist()) {
                app(SendInvoice::class)($invoice);
            }

            return;
        }

        if ($invoice->wasRecentlyCreated) {
            app(InvoiceNotifier::class)->pendingReview($invoice);
        }
    }

    /**
     * The idempotency key of the run at `$slot`: the month (`2026-10`) of a
     * monthly recurrence, otherwise the day (`2026-10-01`), on the owner's
     * calendar.
     */
    public function periodKeyFor(CarbonImmutable $slot): string
    {
        $local = $slot->setTimezone($this->ownerTimezone());

        return $this->recurrence?->frequency->slug === RecurrenceFrequency::MONTHLY
            ? $local->format('Y-m')
            : $local->toDateString();
    }

    /**
     * An unsaved invoice of this recurrence for the given dates and number:
     * its amount, texts, language and currency, and a snapshot of the payer and issuer
     * as they are now. Works on an unsaved recurrence too (the preview of a
     * form), as long as its payer, issuer and currency relations are set.
     */
    public function newInvoice(InvoiceDates $dates, string $number, int $sequence): Invoice
    {
        $invoice = new Invoice([
            'workspace_id' => $this->workspace_id,
            'invoice_recurrence_id' => $this->id,
            'number' => $number,
            'number_year_month' => $dates->issueDate->format('Ym'),
            'number_sequence' => $sequence,
            'issue_date' => $dates->issueDate->toDateString(),
            'due_date' => $dates->dueDate->toDateString(),
            'currency_id' => $this->currency_id,
            'amount' => $this->amount,
            'service_description' => $this->service_description,
            'email_subject' => $this->email_subject,
            'email_body' => $this->email_body,
            'payer_id' => $this->payer_id,
            'payer_name' => $this->payer->name,
            'payer_address' => $this->payer->address,
            'issuer_id' => $this->issuer_id,
            'issuer_name' => $this->issuer->name,
            'issuer_email' => $this->issuer->email,
            'issuer_postal_code' => $this->issuer->postal_code,
            'issuer_street' => $this->issuer->street,
            'issuer_district' => $this->issuer->district,
            'issuer_city' => $this->issuer->city,
            'issuer_state' => $this->issuer->state,
            'mail_service_id' => $this->mail_service_id,
            'locale_id' => $this->locale_id,
        ]);

        return $invoice->setRelation('currency', $this->currency)->setRelation('recurrence', $this);
    }

    public function ownerTimezone(): string
    {
        return $this->createdBy->timezone;
    }

    public function scheduledSlotQueue(): string
    {
        return 'invoices';
    }

    /**
     * Includes a soft-deleted payer, so an existing recurrence still shows who
     * it bills.
     *
     * @return BelongsTo<Payer, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(Payer::class)->withTrashed();
    }

    /**
     * Includes a soft-deleted issuer, like `payer()`.
     *
     * @return BelongsTo<Issuer, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<InvoiceDateRule, $this>
     */
    public function dateRule(): BelongsTo
    {
        return $this->belongsTo(InvoiceDateRule::class, 'invoice_date_rule_id');
    }

    /**
     * @return BelongsTo<InvoiceDueDateRule, $this>
     */
    public function dueDateRule(): BelongsTo
    {
        return $this->belongsTo(InvoiceDueDateRule::class, 'invoice_due_date_rule_id');
    }

    /**
     * @return BelongsTo<InvoiceDeliveryMode, $this>
     */
    public function deliveryMode(): BelongsTo
    {
        return $this->belongsTo(InvoiceDeliveryMode::class, 'invoice_delivery_mode_id');
    }

    /**
     * The service its invoices are e-mailed through; null for the default
     * mailer.
     *
     * @return BelongsTo<MailService, $this>
     */
    public function mailService(): BelongsTo
    {
        return $this->belongsTo(MailService::class);
    }

    /**
     * The language its invoice e-mails are written in.
     *
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }

    /**
     * @return HasMany<InvoiceRecurrenceRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(InvoiceRecurrenceRecipient::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
