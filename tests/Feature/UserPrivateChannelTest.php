<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    Broadcast::purge('reverb');

    require base_path('routes/channels.php');
});

test('user private channel authorizes only its owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($other)
        ->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$owner->id}",
        ])
        ->assertForbidden();
});

test('user private channel authorizes its owner', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$owner->id}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});
