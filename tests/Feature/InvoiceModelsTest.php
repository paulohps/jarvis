<?php

use App\Models\Contracts\Schedulable;
use App\Models\Currency;
use App\Models\DeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceNumberSequence;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceStatus;
use App\Models\Issuer;
use App\Models\Locale;
use App\Models\Payer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

/**
 * An invoice recurrence created without factories, by a user in the given
 * timezone.
 */
function invoiceRecurrenceCreatedIn(string $timezone = 'America/Sao_Paulo'): InvoiceRecurrence
{
    $creator = User::factory()->create(['timezone' => $timezone]);
    $workspaceId = WorkspaceFactory::household()->id;

    $payer = Payer::query()->create(['workspace_id' => $workspaceId, 'name' => 'Acme Inc.', 'address' => "1 Main St\nSpringfield", 'created_by_id' => $creator->id]);
    $issuer = Issuer::query()->create([
        'workspace_id' => $workspaceId, 'name' => 'Paulo', 'email' => 'paulo@example.com', 'postal_code' => '01000-000', 'street' => 'Rua A, 1',
        'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'created_by_id' => $creator->id,
    ]);

    $recurrence = InvoiceRecurrence::query()->create([
        'workspace_id' => $workspaceId,
        'name' => 'Monthly consulting',
        'payer_id' => $payer->id,
        'issuer_id' => $issuer->id,
        'currency_id' => Currency::idFor(Currency::USD),
        'amount' => 1500,
        'service_description' => 'Consulting',
        'email_subject' => 'Invoice {% number %}',
        'email_body' => 'Hi',
        'invoice_date_rule_id' => InvoiceDateRule::idFor(InvoiceDateRule::EXECUTION_DAY),
        'invoice_due_date_rule_id' => InvoiceDueDateRule::idFor(InvoiceDueDateRule::DAYS_AFTER_ISSUE),
        'due_date_value' => 10,
        'invoice_delivery_mode_id' => InvoiceDeliveryMode::idFor(InvoiceDeliveryMode::AUTOMATIC),
        'locale_id' => Locale::idFor(Locale::EN),
        'created_by_id' => $creator->id,
    ]);
    $recurrence->recipients()->create(['email' => 'billing@acme.test']);

    return $recurrence;
}

test('invoice recurrence casts amount and keeps soft deleted payer and issuer', function () {
    $recurrence = invoiceRecurrenceCreatedIn();

    $recurrence->payer->delete();
    $recurrence->issuer->delete();
    $recurrence = $recurrence->fresh();

    expect($recurrence->amount)->toBe('1500.00')
        ->and($recurrence->friendly_id)->toStartWith('irec_')
        ->and($recurrence->payer)->toBeInstanceOf(Payer::class)
        ->and($recurrence->payer->trashed())->toBeTrue()
        ->and($recurrence->issuer->trashed())->toBeTrue()
        ->and($recurrence->payer->invoiceRecurrences->first()->is($recurrence))->toBeTrue()
        ->and($recurrence->currency->slug)->toBe(Currency::USD)
        ->and($recurrence->dateRule->slug)->toBe(InvoiceDateRule::EXECUTION_DAY)
        ->and($recurrence->dueDateRule->slug)->toBe(InvoiceDueDateRule::DAYS_AFTER_ISSUE)
        ->and($recurrence->deliveryMode->slug)->toBe(InvoiceDeliveryMode::AUTOMATIC)
        ->and($recurrence->recipients->pluck('email')->all())->toBe(['billing@acme.test'])
        ->and($recurrence->recipients->first()->invoiceRecurrence->is($recurrence))->toBeTrue();
});

test('invoice recurrence is schedulable in its creator timezone', function () {
    $recurrence = invoiceRecurrenceCreatedIn('Europe/Lisbon');

    expect($recurrence)->toBeInstanceOf(Schedulable::class)
        ->and($recurrence->ownerTimezone())->toBe('Europe/Lisbon')
        ->and(fn () => $recurrence->runScheduledSlot(CarbonImmutable::now()))->not->toThrow(Throwable::class);
});

test('invoice casts dates and derives its status helpers', function () {
    $recurrence = invoiceRecurrenceCreatedIn();
    $approver = User::factory()->create();

    $invoice = Invoice::query()->create([
        'workspace_id' => $recurrence->workspace_id,
        'invoice_recurrence_id' => $recurrence->id,
        'period_key' => '2026-10',
        'number' => '202610-001',
        'number_year_month' => '202610',
        'number_sequence' => 1,
        'invoice_status_id' => InvoiceStatus::idFor(InvoiceStatus::PENDING_REVIEW),
        'issue_date' => '2026-10-01',
        'due_date' => '2026-10-11',
        'currency_id' => $recurrence->currency_id,
        'amount' => '1500',
        'service_description' => $recurrence->service_description,
        'email_subject' => $recurrence->email_subject,
        'email_body' => $recurrence->email_body,
        'payer_id' => $recurrence->payer_id,
        'payer_name' => $recurrence->payer->name,
        'payer_address' => $recurrence->payer->address,
        'issuer_id' => $recurrence->issuer_id,
        'issuer_name' => $recurrence->issuer->name,
        'issuer_email' => $recurrence->issuer->email,
        'issuer_postal_code' => $recurrence->issuer->postal_code,
        'issuer_street' => $recurrence->issuer->street,
        'issuer_district' => $recurrence->issuer->district,
        'issuer_city' => $recurrence->issuer->city,
        'issuer_state' => $recurrence->issuer->state,
        'locale_id' => $recurrence->locale_id,
        'approved_by_id' => $approver->id,
    ]);
    $invoice->recipients()->create(['email' => 'billing@acme.test']);
    $delivery = $invoice->deliveries()->create([
        'delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::SENT),
        'triggered_by_id' => $approver->id,
        'sent_at' => now(),
    ]);
    $sequence = InvoiceNumberSequence::query()->create(['workspace_id' => $recurrence->workspace_id, 'year_month' => '202610', 'last_number' => 1]);

    $recurrence->payer->delete();
    $invoice = $invoice->fresh();

    expect($invoice->issue_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($invoice->issue_date->toDateString())->toBe('2026-10-01')
        ->and($invoice->due_date->toDateString())->toBe('2026-10-11')
        ->and($invoice->amount)->toBe('1500.00')
        ->and($invoice->friendly_id)->toStartWith('invc_')
        ->and($invoice->isPendingReview())->toBeTrue()
        ->and($invoice->isSent())->toBeFalse()
        ->and($invoice->isCancelled())->toBeFalse()
        ->and($invoice->status->slug)->toBe(InvoiceStatus::PENDING_REVIEW)
        ->and($invoice->recurrence->is($recurrence))->toBeTrue()
        ->and($recurrence->invoices->first()->is($invoice))->toBeTrue()
        ->and($invoice->payer->trashed())->toBeTrue()
        ->and($invoice->issuer->invoices->first()->is($invoice))->toBeTrue()
        ->and($invoice->currency->slug)->toBe(Currency::USD)
        ->and($invoice->recipients->first()->invoice->is($invoice))->toBeTrue()
        ->and($invoice->approvedBy->is($approver))->toBeTrue()
        ->and($invoice->createdBy)->toBeNull()
        ->and($delivery->invoice->is($invoice))->toBeTrue()
        ->and($delivery->status->slug)->toBe(DeliveryStatus::SENT)
        ->and($delivery->triggeredBy->is($approver))->toBeTrue()
        ->and($sequence->fresh()->last_number)->toBe(1);

    $invoice->forceFill(['invoice_status_id' => InvoiceStatus::idFor(InvoiceStatus::SENT)]);
    expect($invoice->isSent())->toBeTrue()->and($invoice->isPendingReview())->toBeFalse();

    $invoice->forceFill(['invoice_status_id' => InvoiceStatus::idFor(InvoiceStatus::CANCELLED)]);
    expect($invoice->isCancelled())->toBeTrue();
});
