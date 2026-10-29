<?php

use App\Actions\Invoices\ApproveInvoice;
use App\Actions\Invoices\CancelInvoice;
use App\Actions\Invoices\UpdatePendingInvoice;
use App\Livewire\Invoices\InvoiceReview;
use App\Mail\InvoiceMail;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\Module;
use App\Models\User;
use App\Services\Invoices\InvoiceNumberGenerator;
use App\Services\Invoices\InvoicePdf;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    Storage::fake(InvoicePdf::disk());
    Pdf::fake();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'UTC'));

    $this->user = User::factory()->withModules([Module::INVOICES])->create(['timezone' => 'America/Sao_Paulo']);

    $this->pending = function (string $state = 'pendingReview'): Invoice {
        $invoice = Invoice::factory()->{$state}()->create([
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'amount' => '2000.00',
            'currency_id' => Currency::idFor(Currency::USD),
        ]);
        $invoice->recipients()->create(['email' => 'ap@initech.example']);
        $invoice->forceFill(['pdf_path' => InvoicePdf::store($invoice)])->save();

        return $invoice;
    };

    $this->input = fn (array $overrides = []): array => [
        'service_description' => "Maintenance and support.\nExtra hours in October.",
        'issue_date' => '2026-10-02',
        'due_date' => '2026-11-15',
        'amount' => '3500.50',
        'recipients' => ['ap@initech.example', 'cfo@initech.example'],
        ...$overrides,
    ];

    $this->errorsOf = fn (callable $action): array => rescue($action, fn (ValidationException $exception): array => $exception->errors(), report: false);
});

test('pending invoice can be edited and pdf regenerated', function () {
    $invoice = ($this->pending)();
    $number = $invoice->number;

    $updated = app(UpdatePendingInvoice::class)($this->user, $invoice, ($this->input)());

    expect($updated->number)->toBe($number)
        ->and($updated->service_description)->toBe("Maintenance and support.\nExtra hours in October.")
        ->and($updated->issue_date->toDateString())->toBe('2026-10-02')
        ->and($updated->due_date->toDateString())->toBe('2026-11-15')
        ->and($updated->amount)->toBe('3500.50')
        ->and($updated->recipients()->orderBy('id')->pluck('email')->all())->toBe(['ap@initech.example', 'cfo@initech.example'])
        ->and($updated->isPendingReview())->toBeTrue();

    // The PDF was rendered again from the new values, at the same path.
    Pdf::assertViewHas('amount', '3,500.50');
    Pdf::assertViewHas('dueDate', '15/11/2026');
    expect($updated->pdf_path)->toBe("invoices/{$updated->workspace->friendly_id}/{$number}.pdf");
    Storage::disk(InvoicePdf::disk())->assertExists($updated->pdf_path);

    // Invalid values are refused.
    expect(($this->errorsOf)(fn () => app(UpdatePendingInvoice::class)($this->user, $updated, ($this->input)([
        'due_date' => '2026-09-30',
        'amount' => '0',
        'recipients' => [],
    ]))))->toHaveKeys(['due_date', 'amount', 'recipients']);

    // The review screen edits it too.
    Livewire::actingAs($this->user)
        ->test(InvoiceReview::class, ['invoice' => $updated])
        ->assertSee($number)
        ->set('amount', '1800')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee(__('Invoice :number saved. Its PDF was updated.', ['number' => $number]));

    expect($updated->fresh()->amount)->toBe('1800.00');
});

test('approving sends the invoice and records approver', function () {
    $invoice = ($this->pending)();

    $delivery = app(ApproveInvoice::class)($this->user, $invoice);

    $invoice->refresh();

    expect($invoice->approved_by_id)->toBe($this->user->id)
        ->and($invoice->status->slug)->toBe(InvoiceStatus::SENT)
        ->and($delivery->triggered_by_id)->toBe($this->user->id);

    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->invoice->is($invoice) && $mail->hasTo('ap@initech.example'));

    // Approving again is refused.
    expect(($this->errorsOf)(fn () => app(ApproveInvoice::class)($this->user, $invoice)))->toHaveKey('invoice');
    Mail::assertSentCount(1);
});

test('approving from the review screen', function () {
    $invoice = ($this->pending)();

    Livewire::actingAs($this->user)
        ->test(InvoiceReview::class, ['invoice' => $invoice])
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSee(__('Invoice :number approved and sent to its recipients.', ['number' => $invoice->number]))
        ->assertDontSeeHtml('data-invoice-form');

    expect($invoice->fresh()->isSent())->toBeTrue();
});

test('sent invoice cannot be edited', function () {
    $invoice = ($this->pending)('sent');

    expect(($this->errorsOf)(fn () => app(UpdatePendingInvoice::class)($this->user, $invoice, ($this->input)())))->toHaveKey('invoice')
        ->and(($this->errorsOf)(fn () => app(CancelInvoice::class)($this->user, $invoice)))->toHaveKey('invoice')
        ->and(($this->errorsOf)(fn () => app(ApproveInvoice::class)($this->user, $invoice)))->toHaveKey('invoice');

    expect($invoice->fresh()->amount)->toBe('2000.00')
        ->and($invoice->fresh()->isSent())->toBeTrue();

    Livewire::actingAs($this->user)
        ->test(InvoiceReview::class, ['invoice' => $invoice])
        ->assertDontSeeHtml('data-invoice-form')
        ->assertDontSeeHtml('data-invoice-approve')
        ->assertDontSeeHtml('data-invoice-cancel')
        ->call('save')
        ->assertHasErrors('invoice');
});

test('cancelled invoice keeps its number reserved', function () {
    $invoice = ($this->pending)();
    $number = $invoice->number;

    Livewire::actingAs($this->user)
        ->test(InvoiceReview::class, ['invoice' => $invoice])
        ->call('cancel')
        ->assertHasNoErrors();

    $invoice->refresh();

    expect($invoice->isCancelled())->toBeTrue()
        ->and($invoice->number)->toBe($number)
        ->and(InvoiceNumberGenerator::next($invoice->workspace_id, CarbonImmutable::parse('2026-10-05')))->not->toBe($number)
        ->and(($this->errorsOf)(fn () => app(UpdatePendingInvoice::class)($this->user, $invoice, ($this->input)())))->toHaveKey('invoice');

    Mail::assertNothingSent();
});

test('review screen requires the invoices module', function () {
    $invoice = ($this->pending)();

    $this->actingAs(User::factory()->create())->get(route('invoices.history.show', $invoice))->assertForbidden();
    $this->actingAs($this->user)->get(route('invoices.history.show', $invoice))->assertOk()->assertSee($invoice->number);

    expect(fn () => app(CancelInvoice::class)(User::factory()->create(), $invoice))->toThrow(AuthorizationException::class);
});
