<?php

use App\Actions\Users\DeactivateUser;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappMessage;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('deactivating a user pauses their whatsapp schedules', function () {
    $admin = User::factory()->superAdmin()->create();
    $user = User::factory()->withModules([Module::WHATSAPP])->create();
    $other = User::factory()->withModules([Module::WHATSAPP])->create();

    $recurring = WhatsappMessage::factory()->recurring()->create(['user_id' => $user->id]);
    $once = WhatsappMessage::factory()->once()->create(['user_id' => $user->id]);
    $deleted = WhatsappMessage::factory()->recurring()->create(['user_id' => $user->id]);
    $deleted->delete();
    $othersMessage = WhatsappMessage::factory()->recurring()->create(['user_id' => $other->id]);

    app(DeactivateUser::class)($admin, $user);

    foreach ([$recurring, $once, $deleted] as $message) {
        expect($message->recurrence()->sole()->is_paused)->toBeTrue();
    }

    expect($othersMessage->recurrence()->sole()->is_paused)->toBeFalse();

    // Nothing of theirs is dispatched any more.
    $this->travel(2)->days();
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($recurring->deliveries()->count())->toBe(0);
});
