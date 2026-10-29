<?php

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPasswordForm;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('reset link response is identical for unknown emails', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->get(route('password.request'))->assertOk()->assertSeeLivewire(ForgotPassword::class);

    $known = Livewire::test(ForgotPassword::class)
        ->set('email', 'ana@example.com')
        ->call('sendLink')
        ->assertHasNoErrors()
        ->get('status');

    $unknown = Livewire::test(ForgotPassword::class)
        ->set('email', 'nobody@example.com')
        ->call('sendLink')
        ->assertHasNoErrors()
        ->get('status');

    // A second request within the minute is throttled by the broker, quietly.
    $throttled = Livewire::test(ForgotPassword::class)
        ->set('email', 'ana@example.com')
        ->call('sendLink')
        ->assertHasNoErrors()
        ->get('status');

    expect($known)->toBe(__('passwords.sent'))
        ->and($unknown)->toBe($known)
        ->and($throttled)->toBe($known);

    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    Notification::assertCount(1);
});

test('the reset link opens the new password screen and sets the password', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $token = Password::broker()->createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => 'ana@example.com']))
        ->assertOk()
        ->assertSeeLivewire(ResetPasswordForm::class);

    Livewire::withQueryParams(['email' => 'ana@example.com'])
        ->test(ResetPasswordForm::class, ['token' => $token])
        ->assertSet('email', 'ana@example.com')
        ->set('password', 'new-secret-password')
        ->set('password_confirmation', 'new-secret-password')
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue();
});

test('reset token expires after sixty minutes', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $token = Password::broker()->createToken($user);

    $this->travel(61)->minutes();

    Livewire::test(ResetPasswordForm::class, ['token' => $token])
        ->set('email', 'ana@example.com')
        ->set('password', 'new-secret-password')
        ->set('password_confirmation', 'new-secret-password')
        ->call('resetPassword')
        ->assertHasErrors(['email' => __('passwords.token')]);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('reset token is still valid just before sixty minutes', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $token = Password::broker()->createToken($user);

    $this->travel(59)->minutes();

    Livewire::test(ResetPasswordForm::class, ['token' => $token])
        ->set('email', 'ana@example.com')
        ->set('password', 'new-secret-password')
        ->set('password_confirmation', 'new-secret-password')
        ->call('resetPassword')
        ->assertHasNoErrors();
});

test('reset token cannot be reused', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $token = Password::broker()->createToken($user);

    Livewire::test(ResetPasswordForm::class, ['token' => $token])
        ->set('email', 'ana@example.com')
        ->set('password', 'first-new-password')
        ->set('password_confirmation', 'first-new-password')
        ->call('resetPassword')
        ->assertHasNoErrors();

    Livewire::test(ResetPasswordForm::class, ['token' => $token])
        ->set('email', 'ana@example.com')
        ->set('password', 'second-new-password')
        ->set('password_confirmation', 'second-new-password')
        ->call('resetPassword')
        ->assertHasErrors(['email' => __('passwords.token')]);

    expect(Hash::check('first-new-password', $user->fresh()->password))->toBeTrue();
});

test('the new password must have eight characters and be confirmed', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $token = Password::broker()->createToken($user);

    Livewire::test(ResetPasswordForm::class, ['token' => $token])
        ->set('email', 'ana@example.com')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('resetPassword')
        ->assertHasErrors('password');

    Livewire::test(ResetPasswordForm::class, ['token' => $token])
        ->set('email', 'ana@example.com')
        ->set('password', 'long-enough-password')
        ->set('password_confirmation', 'something-else')
        ->call('resetPassword')
        ->assertHasErrors('password');
});
