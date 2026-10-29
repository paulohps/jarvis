<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\FriendlyRecord;

beforeEach(function () {
    Schema::create('friendly_records', function (Blueprint $table) {
        $table->id();
        $table->friendlyId();
        $table->string('name');
        $table->timestamps();
    });

    Route::middleware('api')->prefix('api/v1')->group(function () {
        Route::get('probe/protected', fn () => ['ok' => true])->middleware('auth:sanctum');
        Route::get('probe/forbidden', fn () => Gate::authorize('never'));
        Route::get('probe/records/{record}', fn (FriendlyRecord $record) => $record)->middleware(SubstituteBindings::class);
        Route::post('probe/validated', fn (Request $request) => $request->validate(['email' => ['required', 'email']]));
        Route::get('probe/broken', fn () => throw new RuntimeException('Secret internals'));
        Route::get('probe/open', fn () => ['ok' => true]);
    });

    Gate::define('never', fn (?User $user = null): bool => false);
});

test('api errors are always json', function () {
    $this->get('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

test('api routes are versioned under v1', function () {
    $this->get('/api/v1/probe/open')->assertOk();
    $this->get('/api/probe/open')->assertNotFound()->assertJsonStructure(['message']);
});

test('unauthenticated api requests get a 401 json without redirect', function () {
    $this->get('/api/v1/probe/protected')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('forbidden api requests get a 403 json', function () {
    $this->get('/api/v1/probe/forbidden')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

test('a missing record answers 404 json without leaking the model', function () {
    config(['app.debug' => false]);

    $this->get('/api/v1/probe/records/frec_missing')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);
});

test('validation errors follow the laravel format', function () {
    $this->post('/api/v1/probe/validated', ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['email']]);
});

test('server errors answer json', function () {
    config(['app.debug' => false]);

    $this->get('/api/v1/probe/broken')
        ->assertInternalServerError()
        ->assertExactJson(['message' => 'Server Error']);
});

test('the api limiter allows 60 requests per minute per client', function () {
    foreach (range(1, 60) as $attempt) {
        $this->get('/api/v1/probe/open')->assertOk();
    }

    $this->get('/api/v1/probe/open')
        ->assertTooManyRequests()
        ->assertJsonStructure(['message']);
});

test('the api limiter counts each user separately', function () {
    $user = User::factory()->create();
    $token = $user->createToken('iPhone')->plainTextToken;

    foreach (range(1, 60) as $attempt) {
        $this->get('/api/v1/probe/open')->assertOk();
    }

    $this->withToken($token)->get('/api/v1/probe/open')->assertOk();
});
