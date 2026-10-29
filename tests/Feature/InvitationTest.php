<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\InviteUser;
use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Exceptions\InvalidInvitation;
use App\Models\Invitation;
use App\Models\Locale;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\InvitationReceived;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    app(NotificationProvisioner::class)->provisionAll();

    Notification::fake();

    $this->freezeSecond();

    $this->admin = User::factory()->superAdmin()->create(['name' => 'Paulo']);
    $this->workspace = WorkspaceFactory::household();
});

/**
 * The plain tokens of every invitation e-mail sent so far, oldest first.
 *
 * @return list<string>
 */
function sentInvitationTokens(): array
{
    return Notification::sent(new AnonymousNotifiable, InvitationReceived::class)
        ->map(fn (InvitationReceived $notification): string => basename($notification->acceptUrl))
        ->values()
        ->all();
}

test('super admin invites a family member with modules', function () {
    $invitation = app(InviteUser::class)($this->admin, [
        'email' => ' Ana@Example.com ',
        'workspace' => $this->workspace->friendly_id,
        'locale' => Locale::PT_BR,
        'modules' => [Module::SHOPPING, Module::REMINDERS],
    ]);

    expect($invitation->email)->toBe('ana@example.com')
        ->and($invitation->status)->toBe(Invitation::STATUS_PENDING)
        ->and($invitation->invited_by_id)->toBe($this->admin->id)
        ->and($invitation->workspace_id)->toBe($this->workspace->id)
        ->and($invitation->locale_id)->toBe(Locale::idFor(Locale::PT_BR))
        ->and($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($invitation->modules()->pluck('slug')->sort()->values()->all())->toBe([Module::REMINDERS, Module::SHOPPING]);

    Notification::assertSentOnDemand(
        InvitationReceived::class,
        fn (InvitationReceived $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana@example.com'
            && $notification->invitation->is($invitation),
    );

    [$token] = sentInvitationTokens();

    expect(strlen($token))->toBe(64)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toContain($token)
        ->and(Invitation::findByToken($token)?->is($invitation))->toBeTrue();
});

test('the invitation defaults to english and no modules', function () {
    $invitation = app(InviteUser::class)($this->admin, ['workspace' => $this->workspace->friendly_id, 'email' => 'ana@example.com']);

    expect($invitation->locale_id)->toBe(Locale::idFor(Locale::EN))
        ->and($invitation->modules()->count())->toBe(0);
});

test('the invitation needs a workspace', function () {
    expect(fn () => app(InviteUser::class)($this->admin, ['email' => 'ana@example.com']))
        ->toThrow(function (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['workspace']);
        });

    expect(Invitation::query()->count())->toBe(0);
});

test('the invitation e-mail names the workspace', function () {
    $costa = Workspace::factory()->create(['name' => 'Costa family']);

    $invitation = app(InviteUser::class)($this->admin, ['workspace' => $costa->friendly_id, 'email' => 'ana@example.com']);

    Notification::assertSentOnDemand(InvitationReceived::class, function (InvitationReceived $notification) use ($invitation): bool {
        $context = $notification->context(new AnonymousNotifiable);

        return $notification->invitation->is($invitation) && $context['workspace']['name'] === 'Costa family';
    });
});

test('inviting an existing user fails validation', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    try {
        app(InviteUser::class)($this->admin, ['workspace' => $this->workspace->friendly_id, 'email' => 'ANA@example.com']);

        $this->fail('The invitation was created.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['email' => ['User already exists.']]);
    }

    expect(Invitation::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

test('the invitation validates its e-mail, locale and modules', function () {
    expect(fn () => app(InviteUser::class)($this->admin, [
        'email' => 'not-an-email',
        'workspace' => 'wsp_unknown',
        'locale' => 'de',
        'modules' => ['unknown'],
    ]))->toThrow(function (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['email', 'workspace', 'locale', 'modules.0']);
    });
});

test('inviting the same e-mail again revokes the earlier pending invitation', function () {
    $first = app(InviteUser::class)($this->admin, ['workspace' => $this->workspace->friendly_id, 'email' => 'ana@example.com']);
    $second = app(InviteUser::class)($this->admin, ['workspace' => $this->workspace->friendly_id, 'email' => 'ana@example.com']);

    expect($first->fresh()->status)->toBe(Invitation::STATUS_REVOKED)
        ->and($second->fresh()->status)->toBe(Invitation::STATUS_PENDING);
});

test('resending invalidates the previous token', function () {
    $invitation = app(InviteUser::class)($this->admin, ['workspace' => $this->workspace->friendly_id, 'email' => 'ana@example.com']);
    $oldHash = $invitation->token_hash;

    $this->travel(3)->days();

    app(ResendInvitation::class)($this->admin, $invitation);

    [$oldToken, $newToken] = sentInvitationTokens();
    $invitation->refresh();

    expect($invitation->token_hash)->not->toBe($oldHash)
        ->and($invitation->token_hash)->toBe(hash('sha256', $newToken))
        ->and(Invitation::findByToken($oldToken))->toBeNull()
        ->and(Invitation::findByToken($newToken)?->is($invitation))->toBeTrue()
        ->and($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
});

test('only a pending invitation is resent or revoked', function (string $state) {
    $invitation = Invitation::factory()->{$state}()->create();

    expect(fn () => app(ResendInvitation::class)($this->admin, $invitation))->toThrow(ValidationException::class)
        ->and(fn () => app(RevokeInvitation::class)($this->admin, $invitation))->toThrow(ValidationException::class);

    Notification::assertNothingSent();
})->with(['expired', 'accepted', 'revoked']);

test('revoked invitation cannot be accepted', function () {
    $invitation = app(InviteUser::class)($this->admin, ['workspace' => $this->workspace->friendly_id, 'email' => 'ana@example.com']);
    [$token] = sentInvitationTokens();

    app(RevokeInvitation::class)($this->admin, $invitation);

    expect($invitation->fresh()->status)->toBe(Invitation::STATUS_REVOKED)
        ->and(fn () => app(AcceptInvitation::class)($token, [
            'name' => 'Ana',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]))->toThrow(InvalidInvitation::class)
        ->and(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse();

    $this->get(route('invitations.accept', $token))->assertStatus(410);
});

test('non super admin cannot invite', function () {
    $member = User::factory()->create();
    $invitation = Invitation::factory()->create();

    expect(fn () => app(InviteUser::class)($member, ['workspace' => $this->workspace->friendly_id, 'email' => 'ana@example.com']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResendInvitation::class)($member, $invitation))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevokeInvitation::class)($member, $invitation))->toThrow(AuthorizationException::class)
        ->and(Invitation::query()->count())->toBe(1)
        ->and($invitation->fresh()->status)->toBe(Invitation::STATUS_PENDING);

    Notification::assertNothingSent();
});
