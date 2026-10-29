<?php

use App\Mail\InvoiceMail;
use App\Models\DeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceStatus;
use App\Models\Module;
use App\Models\Recurrence;
use App\Models\User;
use App\Services\Invoices\InvoicePdf;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\InvoicePendingReview;
use App\Services\Notifications\Types\InvoiceSent;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    app(NotificationProvisioner::class)->provisionAll();
    Storage::fake(InvoicePdf::disk());
    Pdf::fake();
    Mail::fake();

    $this->owner = User::factory()->withModules([Module::INVOICES])->create(['timezone' => 'America/Sao_Paulo']);

    // Monthly on day 1 at 08:00 in São Paulo (Workflow 7), due on 01/10/2026.
    $this->scheduled = function (string $mode): InvoiceRecurrence {
        $invoiceRecurrence = InvoiceRecurrence::factory()
            ->{$mode}()
            ->withRecipients(2)
            ->for($this->owner, 'createdBy')
            ->has(Recurrence::factory()->monthly(1)->state([
                'time_of_day' => '08:00',
                'timezone' => 'America/Sao_Paulo',
                'starts_at' => '2026-09-01 00:00:00',
                'next_run_at' => '2026-10-01 11:00:00',
            ]), 'recurrence')
            ->create([
                'invoice_due_date_rule_id' => InvoiceDueDateRule::idFor(InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH),
                'due_date_value' => null,
            ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-01 11:00:20', 'UTC'));

        return $invoiceRecurrence;
    };
});

test('scheduled slot generates and sends automatic invoice', function () {
    $invoiceRecurrence = ($this->scheduled)('automatic');

    $this->artisan('recurrences:dispatch')->assertSuccessful();

    $invoice = Invoice::query()->sole();

    expect($invoice->number)->toBe('202610-001')
        ->and($invoice->invoice_recurrence_id)->toBe($invoiceRecurrence->id)
        ->and($invoice->period_key)->toBe('2026-10')
        ->and($invoice->issue_date->toDateString())->toBe('2026-10-01')
        ->and($invoice->due_date->toDateString())->toBe('2026-10-31')
        ->and($invoice->status->slug)->toBe(InvoiceStatus::SENT)
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($invoice->deliveries()->sole()->status->slug)->toBe(DeliveryStatus::SENT)
        ->and($invoice->deliveries()->sole()->triggered_by_id)->toBeNull();

    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->invoice->is($invoice));

    expect($this->owner->notifications()->sole()->data['key'])->toBe(InvoiceSent::key());

    // The same slot run again neither issues nor sends anything new.
    $invoiceRecurrence->runScheduledSlot(CarbonImmutable::parse('2026-10-01 11:00', 'UTC'));

    expect(Invoice::query()->count())->toBe(1)
        ->and($invoice->deliveries()->count())->toBe(1);
    Mail::assertSentCount(1);
});

test('scheduled slot in review mode notifies pending review', function () {
    Notification::fake();
    $outsider = User::factory()->create();
    $invoiceRecurrence = ($this->scheduled)('requiresReview');

    $this->artisan('recurrences:dispatch')->assertSuccessful();

    $invoice = Invoice::query()->sole();

    expect($invoice->number)->toBe('202610-001')
        ->and($invoice->status->slug)->toBe(InvoiceStatus::PENDING_REVIEW)
        ->and($invoice->deliveries()->count())->toBe(0);

    Mail::assertNothingSent();
    Notification::assertSentTo($this->owner, InvoicePendingReview::class, fn (InvoicePendingReview $notification): bool => $notification->invoice->is($invoice));
    Notification::assertNotSentTo($outsider, InvoicePendingReview::class);

    // A retried slot does not notify twice.
    $invoiceRecurrence->runScheduledSlot(CarbonImmutable::parse('2026-10-01 11:00', 'UTC'));

    Notification::assertSentToTimes($this->owner, InvoicePendingReview::class, 1);
});

test('period key is the month for monthly recurrences and the day otherwise', function () {
    $monthly = ($this->scheduled)('automatic');
    $daily = InvoiceRecurrence::factory()
        ->for($this->owner, 'createdBy')
        ->has(Recurrence::factory()->daily(), 'recurrence')
        ->create();

    // 01:00 UTC on November 1st is still October 31st in São Paulo.
    $slot = CarbonImmutable::parse('2026-11-01 01:00', 'UTC');

    expect($monthly->periodKeyFor($slot))->toBe('2026-10')
        ->and($daily->periodKeyFor($slot))->toBe('2026-10-31');
});
