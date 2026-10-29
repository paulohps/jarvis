<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Exceptions\InvalidInvitation;
use App\Livewire\Auth\AcceptInvitationForm;
use App\Models\Invitation;
use App\Models\Locale;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->token = Invitation::newToken();
});

/**
 * @param  list<string>  $moduleSlugs
 */
function invitationWithToken(string $token, array $moduleSlugs = [], array $attributes = [], ?string $state = null): Invitation
{
    $factory = Invitation::factory();

    if ($state !== null) {
        $factory = $factory->{$state}();
    }

    $invitation = $factory->create(['token_hash' => Invitation::hashToken($token), ...$attributes]);

    $invitation->modules()->sync(array_map(fn (string $slug): int => Module::idFor($slug), $moduleSlugs));

    return $invitation;
}

test('accepting creates user in the workspace with invited modules and logs in', function () {
    $workspace = Workspace::factory()->create(['name' => 'Costa family']);
    $invitation = invitationWithToken($this->token, [Module::SHOPPING, Module::REMINDERS], [
        'email' => 'ana@example.com',
        'workspace_id' => $workspace->id,
        'locale_id' => Locale::idFor(Locale::PT_BR),
    ]);

    $this->get(route('invitations.accept', $this->token))
        ->assertOk()
        ->assertSeeLivewire(AcceptInvitationForm::class)
        ->assertSee('ana@example.com')
        ->assertSee('data-invited-email', false);

    Livewire::test(AcceptInvitationForm::class, ['token' => $this->token])
        ->assertSet('email', 'ana@example.com')
        ->set('name', 'Ana')
        ->set('password', 'secret-password')
        ->set('password_confirmation', 'secret-password')
        ->call('accept')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'ana@example.com')->sole();
    $invitation->refresh();

    expect($user->name)->toBe('Ana')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue()
        ->and($user->locale->slug)->toBe(Locale::PT_BR)
        ->and($user->isSuperAdmin())->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->memberships)->toHaveCount(1)
        ->and($user->membershipOf($workspace)->is_default)->toBeTrue()
        ->and($user->membershipOf($workspace)->added_by_id)->toBe($invitation->invited_by_id)
        ->and($user->membershipOf($workspace)->modules->pluck('slug')->sort()->values()->all())->toBe([Module::REMINDERS, Module::SHOPPING])
        ->and($user->membershipOf($workspace)->modules->pluck('pivot.enabled_by_id')->unique()->values()->all())->toBe([$invitation->invited_by_id])
        ->and($user->hasModuleIn($workspace, Module::SHOPPING))->toBeTrue()
        ->and($invitation->status)->toBe(Invitation::STATUS_ACCEPTED)
        ->and($invitation->accepted_user_id)->toBe($user->id);

    $this->assertAuthenticatedAs($user);
});

test('the email cannot be changed when accepting', function () {
    invitationWithToken($this->token, [], ['email' => 'ana@example.com']);

    Livewire::test(AcceptInvitationForm::class, ['token' => $this->token])
        ->set('email', 'mallory@example.com');
})->throws(CannotUpdateLockedPropertyException::class);

test('expired invitation cannot be accepted', function () {
    invitationWithToken($this->token, [], ['email' => 'ana@example.com'], 'expired');

    $this->get(route('invitations.accept', $this->token))
        ->assertStatus(410)
        ->assertSee('This invitation is no longer valid');

    expect(fn () => app(AcceptInvitation::class)($this->token, [
        'name' => 'Ana',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]))->toThrow(InvalidInvitation::class);

    expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse();

    $this->assertGuest();
});

test('an invitation that expires while the form is open is not accepted', function () {
    invitationWithToken($this->token, [], ['email' => 'ana@example.com']);

    $component = Livewire::test(AcceptInvitationForm::class, ['token' => $this->token]);

    $this->travel(Invitation::VALIDITY_DAYS + 1)->days();

    $component->set('name', 'Ana')
        ->set('password', 'secret-password')
        ->set('password_confirmation', 'secret-password')
        ->call('accept')
        ->assertRedirect(route('invitations.accept', $this->token));

    expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse();
});

test('used invitation cannot be accepted twice', function () {
    invitationWithToken($this->token, [Module::INVOICES], ['email' => 'ana@example.com']);

    $input = ['name' => 'Ana', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'];

    app(AcceptInvitation::class)($this->token, $input);
    auth()->logout();

    expect(fn () => app(AcceptInvitation::class)($this->token, [...$input, 'name' => 'Ana again']))
        ->toThrow(InvalidInvitation::class);

    $this->get(route('invitations.accept', $this->token))->assertStatus(410);

    expect(User::query()->where('email', 'ana@example.com')->count())->toBe(1)
        ->and(User::query()->where('email', 'ana@example.com')->sole()->name)->toBe('Ana');
});

test('revoked and unknown invitation links show the error page', function () {
    invitationWithToken($this->token, [], ['email' => 'ana@example.com'], 'revoked');

    $this->get(route('invitations.accept', $this->token))->assertStatus(410);
    $this->get(route('invitations.accept', 'not-a-token'))->assertStatus(410);

    expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse();
});

test('password must have at least eight characters and be confirmed', function () {
    invitationWithToken($this->token, [], ['email' => 'ana@example.com']);

    Livewire::test(AcceptInvitationForm::class, ['token' => $this->token])
        ->set('name', 'Ana')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('accept')
        ->assertHasErrors('password');

    Livewire::test(AcceptInvitationForm::class, ['token' => $this->token])
        ->set('name', 'Ana')
        ->set('password', 'secret-password')
        ->set('password_confirmation', 'other-password')
        ->call('accept')
        ->assertHasErrors('password');

    Livewire::test(AcceptInvitationForm::class, ['token' => $this->token])
        ->set('password', 'secret-password')
        ->set('password_confirmation', 'secret-password')
        ->call('accept')
        ->assertHasErrors('name');

    expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse()
        ->and(Invitation::query()->sole()->status)->toBe(Invitation::STATUS_PENDING);

    $this->assertGuest();
});
