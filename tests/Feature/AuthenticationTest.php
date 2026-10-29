<?php

use App\Actions\Auth\AuthenticateUser;
use App\Livewire\Auth\Login;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('user logs in with valid credentials', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSeeLivewire(Login::class);

    Livewire::test(Login::class)
        ->set('email', 'ana@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('invalid credentials show generic error without revealing email existence', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $wrongPassword = Livewire::test(Login::class)
        ->set('email', 'ana@example.com')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email')
        ->errors()->get('email');

    $unknownEmail = Livewire::test(Login::class)
        ->set('email', 'nobody@example.com')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email')
        ->errors()->get('email');

    expect($wrongPassword)->toBe([__('auth.failed')])
        ->and($unknownEmail)->toBe($wrongPassword);

    $this->assertGuest();
});

test('login is throttled after five attempts per minute', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    foreach (range(1, AuthenticateUser::MAX_ATTEMPTS) as $attempt) {
        Livewire::test(Login::class)
            ->set('email', 'ana@example.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email' => __('auth.failed')]);
    }

    // The sixth attempt is refused even with the right password.
    $errors = Livewire::test(Login::class)
        ->set('email', 'ana@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email')
        ->errors()->get('email');

    expect($errors[0])->not->toBe(__('auth.failed'))
        ->and($errors[0])->toContain('seconds');

    $this->assertGuest();

    // Another e-mail from the same IP is not locked out.
    expect(RateLimiter::tooManyAttempts(AuthenticateUser::throttleKey('bia@example.com', '127.0.0.1'), AuthenticateUser::MAX_ATTEMPTS))->toBeFalse();

    $this->travel(AuthenticateUser::DECAY_SECONDS + 1)->seconds();

    Livewire::test(Login::class)
        ->set('email', 'ana@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

test('deactivated user cannot log in', function () {
    User::factory()->deactivated()->create(['email' => 'ana@example.com']);
    User::factory()->create(['email' => 'bia@example.com'])->delete();

    foreach (['ana@example.com', 'bia@example.com'] as $email) {
        Livewire::test(Login::class)
            ->set('email', $email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email' => __('auth.failed')]);
    }

    $this->assertGuest();
});

test('logout invalidates the session and regenerates the csrf token', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['marker' => 'kept?']);
    $tokenBefore = session()->token();

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();

    expect(session()->has('marker'))->toBeFalse()
        ->and(session()->token())->not->toBe($tokenBefore);
});

test('there is no public registration route', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['email' => 'ana@example.com'])->assertClientError();

    expect(Route::has('register'))->toBeFalse()
        ->and(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse();
});
