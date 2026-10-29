<?php

use App\Models\AiProvider;
use App\Models\AiProviderConnection;
use App\Models\Device;
use App\Models\DevicePlatform;
use App\Models\Invitation;
use App\Models\Locale;
use App\Models\Module;
use App\Models\SocialAccount;
use App\Models\SocialProvider;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

/**
 * An invitation sent by a new super admin, with the given timestamps.
 *
 * @param  array<string, mixed>  $timestamps
 */
function invitationWith(array $timestamps): Invitation
{
    $invitation = Invitation::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'token_hash' => hash('sha256', fake()->uuid()),
        'locale_id' => Locale::idFor(Locale::EN),
        'invited_by_id' => User::factory()->superAdmin()->create()->id,
        'expires_at' => now()->addDays(Invitation::VALIDITY_DAYS),
    ]);

    $invitation->forceFill($timestamps)->save();

    return $invitation;
}

test('invitation status is derived from timestamps', function () {
    $invitee = User::factory()->create();

    expect(invitationWith([])->status)->toBe(Invitation::STATUS_PENDING)
        ->and(invitationWith(['expires_at' => now()->subMinute()])->status)->toBe('expired')
        ->and(invitationWith(['accepted_at' => now(), 'accepted_user_id' => $invitee->id])->status)->toBe('accepted')
        ->and(invitationWith(['revoked_at' => now()])->status)->toBe('revoked')
        ->and(invitationWith(['revoked_at' => now(), 'expires_at' => now()->subDay()])->status)->toBe('revoked')
        ->and(invitationWith(['accepted_at' => now()->subDays(8), 'expires_at' => now()->subDay()])->status)->toBe('accepted');
});

test('invitation relations are navigable', function () {
    $invitee = User::factory()->create();
    $invitation = invitationWith(['accepted_user_id' => $invitee->id, 'accepted_at' => now()]);
    $invitation->modules()->attach([Module::idFor(Module::SHOPPING), Module::idFor(Module::REMINDERS)]);

    expect($invitation->modules->pluck('slug')->all())->toEqualCanonicalizing(['shopping', 'reminders'])
        ->and($invitation->invitedBy->isSuperAdmin())->toBeTrue()
        ->and($invitation->invitedBy->invitationsSent->first()->is($invitation))->toBeTrue()
        ->and($invitation->acceptedUser->is($invitee))->toBeTrue()
        ->and($invitation->locale->slug)->toBe(Locale::EN)
        ->and(Module::bySlug(Module::SHOPPING)->invitations->first()->is($invitation))->toBeTrue()
        ->and($invitation->toArray())->not->toHaveKey('token_hash');
});

test('ai provider api key is encrypted at rest', function () {
    $connection = AiProviderConnection::query()->create([
        'ai_provider_id' => AiProvider::idFor(AiProvider::ANTHROPIC),
        'name' => 'Claude',
        'model' => 'claude-sonnet-5-5',
        'models' => ['claude-sonnet-5-5'],
        'api_key' => 'sk-ant-secret-1234',
        'api_key_last_four' => '1234',
        'created_by_id' => User::factory()->superAdmin()->create()->id,
    ]);

    $rawApiKey = DB::table('ai_provider_connections')->where('id', $connection->id)->value('api_key');

    expect($rawApiKey)->not->toBe('sk-ant-secret-1234')
        ->and($rawApiKey)->not->toContain('sk-ant-secret')
        ->and($connection->fresh()->api_key)->toBe('sk-ant-secret-1234')
        ->and($connection->toArray())->not->toHaveKey('api_key')
        ->and($connection->fresh()->toArray())->not->toHaveKey('api_key')
        ->and($connection->provider->slug)->toBe(AiProvider::ANTHROPIC)
        ->and($connection->createdBy->isSuperAdmin())->toBeTrue();
});

test('social accounts and devices belong to their user', function () {
    $user = User::factory()->create();
    $token = $user->createToken('iPhone');

    $socialAccount = SocialAccount::query()->create([
        'user_id' => $user->id,
        'social_provider_id' => SocialProvider::idFor(SocialProvider::GOOGLE),
        'provider_user_id' => '1234567890',
        'email' => $user->email,
    ]);
    $device = Device::query()->create([
        'user_id' => $user->id,
        'personal_access_token_id' => $token->accessToken->id,
        'device_platform_id' => DevicePlatform::idFor(DevicePlatform::IOS),
        'name' => 'iPhone',
    ]);

    expect($user->socialAccounts->first()->is($socialAccount))->toBeTrue()
        ->and($socialAccount->provider->slug)->toBe(SocialProvider::GOOGLE)
        ->and($socialAccount->user->is($user))->toBeTrue()
        ->and($user->devices->first()->is($device))->toBeTrue()
        ->and($device->platform->slug)->toBe(DevicePlatform::IOS)
        ->and($device->personalAccessToken->name)->toBe('iPhone')
        ->and($device->friendly_id)->toStartWith('dev_');
});
