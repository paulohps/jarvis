<?php

use App\Models\Contracts\Schedulable;
use App\Models\DeliveryStatus;
use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappTargetType;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('whatsapp relations are navigable', function () {
    $owner = User::factory()->create(['timezone' => 'Europe/Lisbon']);
    $account = WhatsappAccount::query()->create([
        'user_id' => $owner->id,
        'nickname' => 'Personal',
        'instance_name' => 'jarvis-personal',
        'whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::OPEN),
    ]);
    $contact = $account->targets()->create([
        'whatsapp_target_type_id' => WhatsappTargetType::idFor(WhatsappTargetType::CONTACT),
        'jid' => '5511999990000@s.whatsapp.net',
        'name' => 'Ana',
    ]);
    $group = $account->targets()->create([
        'whatsapp_target_type_id' => WhatsappTargetType::idFor(WhatsappTargetType::GROUP),
        'jid' => '120363000000000000@g.us',
        'name' => 'Family',
    ]);
    $message = $account->messages()->create(['user_id' => $owner->id, 'body' => 'Hi {% name %}']);
    $message->targets()->attach([$contact->id, $group->id]);
    $delivery = $account->deliveries()->create([
        'whatsapp_target_id' => $contact->id,
        'whatsapp_message_id' => $message->id,
        'whatsapp_delivery_source_id' => WhatsappDeliverySource::idFor(WhatsappDeliverySource::SCHEDULED),
        'delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::PENDING),
        'body' => 'Hi Ana',
        'scheduled_for' => now(),
        'triggered_by_id' => $owner->id,
    ]);

    expect($account->user->is($owner))->toBeTrue()
        ->and($owner->whatsappAccounts->first()->is($account))->toBeTrue()
        ->and($account->status->slug)->toBe(WhatsappConnectionStatus::OPEN)
        ->and($account->isOpen())->toBeTrue()
        ->and($account->friendly_id)->toStartWith('wacc_')
        ->and($account->targets)->toHaveCount(2)
        ->and($contact->account->is($account))->toBeTrue()
        ->and($contact->type->slug)->toBe(WhatsappTargetType::CONTACT)
        ->and($contact->fresh()->is_synced)->toBeTrue()
        ->and($group->messages->first()->is($message))->toBeTrue()
        ->and($message->targets->pluck('id')->all())->toEqualCanonicalizing([$contact->id, $group->id])
        ->and($message->user->is($owner))->toBeTrue()
        ->and($message->account->is($account))->toBeTrue()
        ->and($message->deliveries->first()->is($delivery))->toBeTrue()
        ->and($message->ownerTimezone())->toBe('Europe/Lisbon')
        ->and($message)->toBeInstanceOf(Schedulable::class)
        ->and($delivery->account->is($account))->toBeTrue()
        ->and($delivery->target->is($contact))->toBeTrue()
        ->and($delivery->message->is($message))->toBeTrue()
        ->and($delivery->source->slug)->toBe(WhatsappDeliverySource::SCHEDULED)
        ->and($delivery->status->slug)->toBe(DeliveryStatus::PENDING)
        ->and($delivery->triggeredBy->is($owner))->toBeTrue()
        ->and($account->deliveries->first()->is($delivery))->toBeTrue();

    $message->delete();

    expect($delivery->fresh()->message->trashed())->toBeTrue()
        ->and($group->fresh()->messages)->toBeEmpty();
});

test('whatsapp account is open only with the open status', function (string $statusSlug, bool $isOpen) {
    $account = new WhatsappAccount(['whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor($statusSlug)]);

    expect($account->isOpen())->toBe($isOpen);
})->with([
    [WhatsappConnectionStatus::OPEN, true],
    [WhatsappConnectionStatus::CONNECTING, false],
    [WhatsappConnectionStatus::CLOSED, false],
]);

test('whatsapp message is once when its recurrence frequency is once', function () {
    $owner = User::factory()->create();
    $account = WhatsappAccount::query()->create([
        'user_id' => $owner->id,
        'nickname' => 'Personal',
        'instance_name' => 'jarvis-once',
        'whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::OPEN),
    ]);
    $once = $account->messages()->create(['user_id' => $owner->id, 'body' => 'Once']);
    $daily = $account->messages()->create(['user_id' => $owner->id, 'body' => 'Daily']);
    $withoutRule = $account->messages()->create(['user_id' => $owner->id, 'body' => 'No rule']);

    Recurrence::factory()->once()->for($once, 'schedulable')->create();
    Recurrence::factory()->daily()->for($daily, 'schedulable')->create();

    expect($once->fresh()->isOnce())->toBeTrue()
        ->and($once->recurrence->frequency->slug)->toBe(RecurrenceFrequency::ONCE)
        ->and($daily->fresh()->isOnce())->toBeFalse()
        ->and($withoutRule->isOnce())->toBeFalse();
});
