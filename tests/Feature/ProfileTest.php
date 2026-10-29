<?php

use App\Actions\Profile\DisconnectSocialAccount;
use App\Actions\Profile\UpdatePassword;
use App\Actions\Profile\UpdatePreferences;
use App\Actions\Profile\UpdateProfile;
use App\Models\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    Storage::fake('public');
});

/**
 * Runs an action and returns the validation errors it threw, failing when it
 * did not answer 422.
 *
 * @return array<string, list<string>>
 */
function profileValidationErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        expect($exception->status)->toBe(422);

        return $exception->errors();
    }

    test()->fail('The action did not refuse the input.');
}

test('avatar must be an image up to two megabytes', function () {
    $user = User::factory()->create(['name' => 'Ana']);
    $updateProfile = app(UpdateProfile::class);

    $tooLarge = profileValidationErrors(fn () => $updateProfile($user, [
        'name' => 'Ana',
        'avatar' => UploadedFile::fake()->image('avatar.jpg')->size(2049),
    ]));
    $otherType = profileValidationErrors(fn () => $updateProfile($user, [
        'name' => 'Ana',
        'avatar' => UploadedFile::fake()->image('avatar.gif')->size(100),
    ]));
    $notAnImage = profileValidationErrors(fn () => $updateProfile($user, [
        'name' => 'Ana',
        'avatar' => UploadedFile::fake()->create('avatar.pdf', 100, 'application/pdf'),
    ]));

    expect($tooLarge)->toHaveKey('avatar')
        ->and($otherType)->toHaveKey('avatar')
        ->and($notAnImage)->toHaveKey('avatar')
        ->and($user->fresh()->avatar_path)->toBeNull();

    foreach (['first.jpg', 'second.png', 'third.webp'] as $name) {
        $previous = $user->fresh()->avatar_path;

        $updateProfile($user, [
            'name' => 'Ana Maria',
            'avatar' => UploadedFile::fake()->image($name)->size(2048),
        ]);

        $stored = $user->fresh()->avatar_path;

        expect($stored)->toStartWith('avatars/')
            ->and($stored)->not->toBe($previous);
        Storage::disk('public')->assertExists($stored);

        if ($previous !== null) {
            Storage::disk('public')->assertMissing($previous);
        }
    }

    expect($user->fresh()->name)->toBe('Ana Maria')
        ->and(Storage::disk('public')->allFiles('avatars'))->toHaveCount(1);
});

test('updating the name keeps the stored avatar', function () {
    $user = User::factory()->create();
    $updateProfile = app(UpdateProfile::class);

    $updateProfile($user, ['name' => 'Ana', 'avatar' => UploadedFile::fake()->image('avatar.png')]);
    $avatar = $user->fresh()->avatar_path;

    $updateProfile($user, ['name' => '  Ana Clara  ']);

    expect($user->fresh())
        ->name->toBe('Ana Clara')
        ->avatar_path->toBe($avatar);
    Storage::disk('public')->assertExists($avatar);

    expect(profileValidationErrors(fn () => $updateProfile($user, ['name' => ''])))->toHaveKey('name');
});

test('changing password requires current password', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    $updatePassword = app(UpdatePassword::class);

    $missing = profileValidationErrors(fn () => $updatePassword($user, [
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]));
    $wrong = profileValidationErrors(fn () => $updatePassword($user, [
        'current_password' => 'not-the-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]));

    expect($missing)->toHaveKey('current_password')
        ->and($wrong['current_password'])->toBe([__('validation.current_password')])
        ->and(Hash::check('old-password', $user->fresh()->password))->toBeTrue();

    $updatePassword($user, [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('google only user can set a first password', function () {
    $user = User::factory()->googleOnly()->create();
    $updatePassword = app(UpdatePassword::class);

    expect(profileValidationErrors(fn () => $updatePassword($user, [
        'password' => 'first-password',
        'password_confirmation' => 'another-password',
    ])))->toHaveKey('password');

    $updatePassword($user, [
        'password' => 'first-password',
        'password_confirmation' => 'first-password',
    ]);

    expect(Hash::check('first-password', $user->fresh()->password))->toBeTrue()
        ->and($user->socialAccounts()->count())->toBe(1);

    // From now on the current password is required.
    expect(profileValidationErrors(fn () => $updatePassword($user->fresh(), [
        'password' => 'second-password',
        'password_confirmation' => 'second-password',
    ])))->toHaveKey('current_password');
});

test('cannot disconnect google without a password', function () {
    $user = User::factory()->googleOnly()->create();
    $account = $user->socialAccounts()->sole();

    $errors = profileValidationErrors(fn () => app(DisconnectSocialAccount::class)($user, $account));

    expect($errors['social_account'])->toBe(['Set a password first.'])
        ->and($account->fresh())->not->toBeNull();
});

test('can disconnect google when a password exists', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->for($user)->create();
    $someoneElsesAccount = SocialAccount::factory()->create();

    app(DisconnectSocialAccount::class)($user, $account);

    expect($account->fresh())->toBeNull()
        ->and(fn () => app(DisconnectSocialAccount::class)($user, $someoneElsesAccount))->toThrow(AuthorizationException::class)
        ->and($someoneElsesAccount->fresh())->not->toBeNull();
});

test('locale must be en or pt_BR', function (mixed $locale) {
    $user = User::factory()->create(['locale_id' => Locale::idFor('en')]);

    expect(profileValidationErrors(fn () => app(UpdatePreferences::class)($user, ['locale' => $locale])))->toHaveKey('locale')
        ->and($user->fresh()->locale->slug)->toBe('en');
})->with(['pt', 'pt-BR', 'es', 'EN', '', null]);

test('locale and timezone are saved on the user', function () {
    $user = User::factory()->create(['locale_id' => Locale::idFor('en'), 'timezone' => 'UTC']);

    app(UpdatePreferences::class)($user, ['locale' => 'pt_BR', 'timezone' => 'America/Sao_Paulo']);

    expect($user->fresh())
        ->locale->slug->toBe('pt_BR')
        ->timezone->toBe('America/Sao_Paulo')
        ->and($user->preferredLocale())->toBe('pt_BR');

    app(UpdatePreferences::class)($user, ['timezone' => 'Europe/Lisbon']);

    expect($user->fresh())
        ->locale->slug->toBe('pt_BR')
        ->timezone->toBe('Europe/Lisbon');
});

test('timezone must be a valid php identifier', function (mixed $timezone) {
    $user = User::factory()->create(['timezone' => 'UTC']);

    expect(profileValidationErrors(fn () => app(UpdatePreferences::class)($user, ['timezone' => $timezone])))->toHaveKey('timezone')
        ->and($user->fresh()->timezone)->toBe('UTC');
})->with(['Mars/Olympus_Mons', 'GMT+3', 'São Paulo', '', null]);

test('next request uses the new locale', function () {
    Route::middleware('web')->get('/profile-locale-probe', fn () => ['locale' => app()->getLocale(), 'text' => __('Sign out')]);

    $user = User::factory()->create(['locale_id' => Locale::idFor('en')]);

    $this->actingAs($user)->get('/profile-locale-probe')->assertExactJson(['locale' => 'en', 'text' => 'Sign out']);

    app(UpdatePreferences::class)($user, ['locale' => 'pt_BR']);

    $this->actingAs($user->fresh())->get('/profile-locale-probe')->assertExactJson(['locale' => 'pt_BR', 'text' => 'Sair']);
});

test('avatars go to the configured media disk', function () {
    config(['filesystems.media' => 'r2-public', 'filesystems.disks.r2-public.url' => 'https://files.example.com']);
    Storage::fake('r2-public');
    $user = User::factory()->create();

    app(UpdateProfile::class)($user, [
        'name' => $user->name,
        'avatar' => UploadedFile::fake()->image('ada.png'),
    ]);

    $stored = $user->fresh()->avatar_path;

    Storage::disk('r2-public')->assertExists($stored);
    Storage::disk('public')->assertMissing($stored);
});
