<?php

use App\Actions\Invoices\CreateIssuer;
use App\Actions\Invoices\DeleteIssuer;
use App\Actions\Invoices\UpdateIssuer;
use App\Livewire\Invoices\IssuerList;
use App\Models\Invoice;
use App\Models\InvoiceRecurrence;
use App\Models\Issuer;
use App\Models\Module;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->user = User::factory()->withModules([Module::INVOICES])->create();

    $this->sample = [
        'name' => 'Jane Doe Consulting Ltda',
        'email' => 'billing@janedoe.example',
        'postal_code' => '01310-100',
        'street' => 'Av. Paulista, 1000',
        'district' => 'Bela Vista',
        'city' => 'São Paulo',
        'state' => 'SP',
    ];
});

test('issuer requires all address fields', function () {
    $exception = rescue(fn () => app(CreateIssuer::class)($this->user, []), fn (ValidationException $exception) => $exception, report: false);

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($exception->errors()))->toEqualCanonicalizing(IssuerList::FIELDS);

    foreach (IssuerList::FIELDS as $field) {
        expect(fn () => app(CreateIssuer::class)($this->user, [...$this->sample, $field => ' ']))
            ->toThrow(ValidationException::class);
    }

    // An invalid e-mail is a 422 like every validation error.
    $invalidEmail = rescue(
        fn () => app(CreateIssuer::class)($this->user, [...$this->sample, 'email' => 'not-an-email']),
        fn (ValidationException $exception) => $exception,
        report: false,
    );

    expect($invalidEmail->status)->toBe(422)
        ->and(array_keys($invalidEmail->errors()))->toBe(['email'])
        ->and(Issuer::query()->count())->toBe(0);

    $issuer = app(CreateIssuer::class)($this->user, $this->sample);

    expect($issuer->only(IssuerList::FIELDS))->toBe($this->sample)
        ->and($issuer->created_by_id)->toBe($this->user->id);

    // N issuers: the sheet adds a second one and shows field errors.
    Livewire::actingAs($this->user)
        ->test(IssuerList::class)
        ->call('create')
        ->set('email', 'nope')
        ->call('save')
        ->assertHasErrors(['name', 'email', 'postal_code', 'street', 'district', 'city', 'state'])
        ->fill([...$this->sample, 'name' => 'Second Issuer Ltda', 'email' => 'second@example.com'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Issuer Second Issuer Ltda saved.');

    expect(Issuer::query()->count())->toBe(2);

    // Editing keeps the snapshot of issued invoices.
    $invoice = Invoice::factory()->oneOff()->sent()->create(['issuer_id' => $issuer->id]);

    app(UpdateIssuer::class)($this->user, $issuer, [...$this->sample, 'city' => 'Campo Grande']);

    expect($issuer->fresh()->city)->toBe('Campo Grande')
        ->and($invoice->fresh()->issuer_city)->toBe('São Paulo');
});

test('issuer used by active recurrence cannot be deleted', function () {
    $issuer = Issuer::factory()->create();
    $recurrence = InvoiceRecurrence::factory()->create(['issuer_id' => $issuer->id]);

    expect(fn () => app(DeleteIssuer::class)($this->user, $issuer))
        ->toThrow(ValidationException::class, 'This issuer cannot be deleted because 1 invoice recurrence uses it.');

    Livewire::actingAs($this->user)
        ->test(IssuerList::class)
        ->call('delete', $issuer->friendly_id)
        ->assertHasErrors('issuer')
        ->assertSee('This issuer cannot be deleted because 1 invoice recurrence uses it.');

    expect($issuer->fresh()->trashed())->toBeFalse();

    $recurrence->delete();

    app(DeleteIssuer::class)($this->user, $issuer);

    expect($issuer->fresh()->trashed())->toBeTrue();

    // Without the module, neither the screen nor the Actions.
    $outsider = User::factory()->withModules([Module::REMINDERS])->create();

    $this->actingAs($outsider)->get(route('invoices.issuers.index'))->assertForbidden();
    $this->actingAs($this->user)->get(route('invoices.issuers.index'))->assertOk();

    expect(fn () => app(CreateIssuer::class)($outsider, $this->sample))->toThrow(AuthorizationException::class);
});
