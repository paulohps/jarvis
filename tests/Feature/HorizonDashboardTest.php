<?php

use App\Models\User;

test('horizon dashboard is forbidden to non super admins', function () {
    $this->actingAs(User::factory()->create())
        ->get('/horizon')
        ->assertForbidden();
});

test('horizon dashboard is forbidden to guests', function () {
    $this->get('/horizon')->assertForbidden();
});

test('horizon dashboard is available to the super admin', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/horizon')
        ->assertOk();
});

test('horizon supervisors cover every application queue', function (string $environment) {
    $queues = collect(config('horizon.defaults'))
        ->only(array_keys(config("horizon.environments.{$environment}")))
        ->pluck('queue')
        ->flatten()
        ->sort()
        ->values()
        ->all();

    expect($queues)->toBe(['chats', 'default', 'invoices', 'notifications', 'whatsapp']);
})->with(['local', 'production']);
