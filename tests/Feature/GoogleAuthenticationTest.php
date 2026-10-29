<?php

use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Models\Invitation;
use App\Models\Module;
use App\Models\SocialAccount;
use App\Models\SocialProvider;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

function fakeGoogleUser(string $email, string $id = '1234567890', bool $verified = true, string $name = 'Ana Google'): void
{
    Socialite::fake('google', SocialiteUser::fake([
        'id' => $id,
        'name' => $name,
        'email' => $email,
    ])->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified]));
}

/**
 * @param  list<string>  $moduleSlugs
 */
function pendingInvitationFor(string $email, string $token, array $moduleSlugs = []): Invitation
{
    $invitation = Invitation::factory()->create([
        'email' => $email,
        'token_hash' => Invitation::hashToken($token),
    ]);

    $invitation->modules()->sync(array_map(fn (string $slug): int => Module::idFor($slug), $moduleSlugs));

    return $invitation;
}

test('the sign-in and invitation screens offer google', function () {
    pendingInvitationFor('ana@example.com', 'the-token');

    $this->get(route('login'))
        ->assertSee('Continue with Google')
        ->assertSee(route('auth.google.redirect'), false);

    $this->get(route('invitations.accept', 'the-token'))
        ->assertSee('Continue with Google')
        ->assertSee(e(route('auth.google.redirect', ['invitation' => 'the-token'])), false);
});

test('the redirect sends the visitor to google and keeps the invitation token', function () {
    Socialite::fake('google');

    $this->get(route('auth.google.redirect', ['invitation' => 'the-token']))
        ->assertRedirect('https://socialite.fake/google/authorize')
        ->assertSessionHas(GoogleRedirectController::INVITATION_SESSION_KEY, 'the-token');

    $this->get(route('auth.google.redirect'))
        ->assertSessionMissing(GoogleRedirectController::INVITATION_SESSION_KEY);
});

test('google login signs in an already linked account', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $account = SocialAccount::factory()->for($user)->create(['provider_user_id' => 'google-1', 'last_used_at' => null]);

    fakeGoogleUser('ana@example.com', 'google-1');

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($account->fresh()->last_used_at)->not->toBeNull();
});

test('google login links existing user on first use', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    fakeGoogleUser('Ana@Example.com', 'google-1');

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    $account = SocialAccount::query()->sole();

    expect($account->user_id)->toBe($user->id)
        ->and($account->social_provider_id)->toBe(SocialProvider::idFor(SocialProvider::GOOGLE))
        ->and($account->provider_user_id)->toBe('google-1')
        ->and($account->email)->toBe('ana@example.com')
        ->and($account->last_used_at)->not->toBeNull()
        ->and(User::query()->count())->toBe(1);

    $this->travel(1)->day();
    auth()->logout();

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    expect(SocialAccount::query()->count())->toBe(1)
        ->and($account->fresh()->last_used_at->greaterThan($account->last_used_at))->toBeTrue();
});

test('google login with pending invitation creates the account', function () {
    $invitation = pendingInvitationFor('ana@example.com', 'the-token', [Module::SHOPPING]);

    fakeGoogleUser('ana@example.com', 'google-1', name: 'Ana Souza');

    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'ana@example.com')->sole();

    $this->assertAuthenticatedAs($user);

    expect($user->name)->toBe('Ana Souza')
        ->and($user->password)->toBeNull()
        ->and($user->membershipOf($invitation->workspace_id)->is_default)->toBeTrue()
        ->and($user->membershipOf($invitation->workspace_id)->modules->pluck('slug')->all())->toBe([Module::SHOPPING])
        ->and($user->socialAccounts()->sole()->provider_user_id)->toBe('google-1')
        ->and($invitation->fresh()->status)->toBe(Invitation::STATUS_ACCEPTED)
        ->and($invitation->fresh()->accepted_user_id)->toBe($user->id);
});

test('google login from the invitation link accepts that invitation', function () {
    $invitation = pendingInvitationFor('ana@example.com', 'the-token', [Module::REMINDERS]);

    fakeGoogleUser('ana@example.com');

    $this->withSession([GoogleRedirectController::INVITATION_SESSION_KEY => 'the-token'])
        ->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard'));

    expect($invitation->fresh()->status)->toBe(Invitation::STATUS_ACCEPTED)
        ->and(User::query()->where('email', 'ana@example.com')->sole()->workspaceIdsWithModule(Module::REMINDERS))->toBe([$invitation->workspace_id]);
});

test('google login without account or invitation is refused', function () {
    Invitation::factory()->expired()->create(['email' => 'ana@example.com']);
    Invitation::factory()->revoked()->create(['email' => 'ana@example.com']);

    fakeGoogleUser('ana@example.com');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas(GoogleCallbackController::ERROR_KEY, 'Invitation required: ask the admin to invite this Google account e-mail.');

    $this->assertGuest();

    expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse()
        ->and(SocialAccount::query()->count())->toBe(0);

    $this->get(route('login'))->assertSee('Invitation required');
});

test('google email different from invitation is refused during acceptance', function () {
    $invitation = pendingInvitationFor('ana@example.com', 'the-token');
    // Even an e-mail with its own pending invitation is refused on this link.
    pendingInvitationFor('other@example.com', 'other-token');

    fakeGoogleUser('other@example.com');

    $this->withSession([GoogleRedirectController::INVITATION_SESSION_KEY => 'the-token'])
        ->get(route('auth.google.callback'))
        ->assertRedirect(route('invitations.accept', 'the-token'))
        ->assertSessionHas(GoogleCallbackController::ERROR_KEY);

    $this->assertGuest();

    expect(User::query()->whereIn('email', ['ana@example.com', 'other@example.com'])->exists())->toBeFalse()
        ->and(SocialAccount::query()->count())->toBe(0)
        ->and($invitation->fresh()->status)->toBe(Invitation::STATUS_PENDING);

    $this->get(route('invitations.accept', 'the-token'))
        ->assertOk()
        ->assertSee('does not use the invited e-mail');
});

test('deactivated user cannot log in with google', function () {
    $deactivated = User::factory()->deactivated()->create(['email' => 'ana@example.com']);
    SocialAccount::factory()->for($deactivated)->create(['provider_user_id' => 'google-1']);
    User::factory()->deactivated()->create(['email' => 'bia@example.com']);

    fakeGoogleUser('ana@example.com', 'google-1');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas(GoogleCallbackController::ERROR_KEY, 'This account is deactivated.');

    fakeGoogleUser('bia@example.com', 'google-2');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas(GoogleCallbackController::ERROR_KEY, 'This account is deactivated.');

    $this->assertGuest();

    expect(SocialAccount::query()->count())->toBe(1);
});

test('an unverified google e-mail is refused', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    fakeGoogleUser('ana@example.com', verified: false);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas(GoogleCallbackController::ERROR_KEY, 'This Google account has no verified e-mail.');

    $this->assertGuest();
    expect(SocialAccount::query()->count())->toBe(0);
});

test('cancelling on google goes back to the sign-in screen', function () {
    Socialite::fake('google');

    $this->get(route('auth.google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHas(GoogleCallbackController::ERROR_KEY);

    $this->assertGuest();
});
