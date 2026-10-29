<?php

use App\Actions\Invoices\CreatePayer;
use App\Actions\Invoices\DeletePayer;
use App\Actions\Invoices\UpdatePayer;
use App\Livewire\Invoices\PayerList;
use App\Models\Invoice;
use App\Models\InvoiceRecurrence;
use App\Models\Module;
use App\Models\Payer;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->user = User::factory()->withModules([Module::INVOICES])->create();
});

test('payer requires name and address', function () {
    $errors = fn (array $input): array => rescue(
        fn () => app(CreatePayer::class)($this->user, $input) && [],
        fn (ValidationException $exception) => array_keys($exception->errors()),
        report: false,
    );

    expect($errors([]))->toEqualCanonicalizing(['name', 'address'])
        ->and($errors(['name' => '   ', 'address' => "\n"]))->toEqualCanonicalizing(['name', 'address'])
        ->and($errors(['name' => str_repeat('a', 256), 'address' => 'PO Box 1234']))->toBe(['name']);

    expect(Payer::query()->count())->toBe(0);

    $payer = app(CreatePayer::class)($this->user, [
        'name' => '  Initech Corp ',
        'address' => "PO Box 1234\r\nSpringfield, IL 62701",
    ]);

    expect($payer->name)->toBe('Initech Corp')
        ->and($payer->address)->toBe("PO Box 1234\nSpringfield, IL 62701")
        ->and($payer->created_by_id)->toBe($this->user->id)
        ->and($payer->friendly_id)->toStartWith('pay');

    // The sheet shows the same errors next to its fields.
    Livewire::actingAs($this->user)
        ->test(PayerList::class)
        ->call('create')
        ->assertSet('showForm', true)
        ->call('save')
        ->assertHasErrors(['name', 'address'])
        ->set('name', 'Acme Inc.')
        ->set('address', "1 Main St\nSpringfield")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSee('Payer Acme Inc. saved.')
        ->assertSee('data-payer="'.Payer::query()->where('name', 'Acme Inc.')->value('friendly_id').'"', false);
});

test('payer used by active recurrence cannot be deleted', function () {
    $payer = Payer::factory()->create();
    $recurrence = InvoiceRecurrence::factory()->create(['payer_id' => $payer->id]);

    expect(fn () => app(DeletePayer::class)($this->user, $payer))
        ->toThrow(ValidationException::class, 'This payer cannot be deleted because 1 invoice recurrence bills it.');

    expect($payer->fresh()->trashed())->toBeFalse();

    Livewire::actingAs($this->user)
        ->test(PayerList::class)
        ->call('delete', $payer->friendly_id)
        ->assertHasErrors('payer')
        ->assertSee('This payer cannot be deleted because 1 invoice recurrence bills it.');

    // Once the recurrence is deleted, the payer can go; it is only soft deleted.
    $recurrence->delete();

    Livewire::actingAs($this->user)
        ->test(PayerList::class)
        ->call('delete', $payer->friendly_id)
        ->assertHasNoErrors()
        ->assertSee("Payer {$payer->name} deleted.")
        ->assertDontSee('data-payer="'.$payer->friendly_id.'"', false);

    expect($payer->fresh()->trashed())->toBeTrue()
        ->and(Payer::query()->count())->toBe(0)
        ->and(Payer::withTrashed()->count())->toBe(1);
});

test('editing payer does not change existing invoice snapshot', function () {
    $payer = Payer::factory()->create(['name' => 'Initech Corp', 'address' => 'PO Box 1234, Springfield, IL 62701']);
    $invoice = Invoice::factory()->oneOff()->sent()->create(['payer_id' => $payer->id]);

    app(UpdatePayer::class)($this->user, $payer, ['name' => 'Initech Holdings', 'address' => 'New address']);

    expect($payer->fresh()->name)->toBe('Initech Holdings')
        ->and($invoice->fresh()->payer_name)->toBe('Initech Corp')
        ->and($invoice->fresh()->payer_address)->toBe('PO Box 1234, Springfield, IL 62701');

    // The sheet edits the same record.
    Livewire::actingAs($this->user)
        ->test(PayerList::class)
        ->call('edit', $payer->friendly_id)
        ->assertSet('name', 'Initech Holdings')
        ->set('name', 'Initech Holdings LLC')
        ->call('save')
        ->assertHasNoErrors();

    expect($payer->fresh()->name)->toBe('Initech Holdings LLC')
        ->and($invoice->fresh()->payer_name)->toBe('Initech Corp');
});

test('users without invoices module cannot manage payers', function () {
    $outsider = User::factory()->withModules([Module::SHOPPING])->create();
    $payer = Payer::factory()->create();

    $this->actingAs($outsider)->get(route('invoices.payers.index'))->assertForbidden();
    $this->actingAs($this->user)->get(route('invoices.payers.index'))->assertOk()->assertSee($payer->name);

    expect(fn () => app(CreatePayer::class)($outsider, ['name' => 'X', 'address' => 'Y']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdatePayer::class)($outsider, $payer, ['name' => 'X', 'address' => 'Y']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeletePayer::class)($outsider, $payer))->toThrow(AuthorizationException::class);

    expect($payer->fresh()->trashed())->toBeFalse()
        ->and(Payer::query()->count())->toBe(1);
});
