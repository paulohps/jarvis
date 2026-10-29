<?php

use App\Jobs\DeliverWhatsappMessage;
use App\Livewire\Whatsapp\MessageForm;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'America/Sao_Paulo']);
    $this->token = $this->owner->createToken('iPhone')->plainTextToken;
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal']);
    $this->ana = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana']);
    $this->family = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Family']);

    $this->input = fn (array $overrides = []): array => [
        'account' => $this->account->friendly_id,
        'targets' => [$this->ana->friendly_id, $this->family->friendly_id],
        'numbers' => [],
        'body' => 'Hi {% recipient.name %}!',
        'recurrence' => ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => '2026-10-06T09:00'],
        ...$overrides,
    ];
});

test('api whatsapp resources are scoped to the owner', function () {
    $other = User::factory()->withModules([Module::WHATSAPP])->create();
    $otherToken = $other->createToken('Pixel')->plainTextToken;
    WhatsappAccount::factory()->open()->for($other)->create(['nickname' => 'Theirs']);

    $message = WhatsappMessage::factory()->recurring()->create(['user_id' => $this->owner->id, 'whatsapp_account_id' => $this->account->id]);
    $message->targets()->attach($this->ana);
    WhatsappDelivery::factory()->create(['whatsapp_account_id' => $this->account->id, 'whatsapp_target_id' => $this->ana->id]);

    // The owner sees their own.
    $this->withToken($this->token)
        ->getJson('/api/v1/whatsapp/accounts')
        ->assertOk()
        ->assertJsonPath('data.*.nickname', ['Personal'])
        ->assertJsonPath('data.0.status', 'open')
        ->assertJsonMissingPath('data.0.instance_name');

    $this->withToken($this->token)
        ->getJson("/api/v1/whatsapp/accounts/{$this->account->friendly_id}/targets?search=an")
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Ana'])
        ->assertJsonPath('data.0.type', 'contact');

    $this->withToken($this->token)
        ->getJson('/api/v1/whatsapp/messages')
        ->assertOk()
        ->assertJsonPath('data.*.friendly_id', [$message->friendly_id])
        ->assertJsonPath('data.0.state', 'active')
        ->assertJsonPath('data.0.recurrence.frequency', 'daily');

    $this->withToken($this->token)->getJson('/api/v1/whatsapp/deliveries')->assertOk()->assertJsonCount(1, 'data');

    // Someone else gets nothing of it, and a 403 on every route of a record.
    app('auth')->forgetGuards();

    $this->withToken($otherToken)->getJson('/api/v1/whatsapp/accounts')->assertOk()->assertJsonPath('data.*.nickname', ['Theirs']);
    $this->withToken($otherToken)->getJson('/api/v1/whatsapp/messages')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($otherToken)->getJson('/api/v1/whatsapp/deliveries')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($otherToken)->getJson("/api/v1/whatsapp/accounts/{$this->account->friendly_id}/targets")->assertForbidden();
    $this->withToken($otherToken)->getJson("/api/v1/whatsapp/messages/{$message->friendly_id}")->assertForbidden();
    $this->withToken($otherToken)->patchJson("/api/v1/whatsapp/messages/{$message->friendly_id}", ($this->input)())->assertForbidden();
    $this->withToken($otherToken)->postJson("/api/v1/whatsapp/messages/{$message->friendly_id}/pause")->assertForbidden();
    $this->withToken($otherToken)->postJson("/api/v1/whatsapp/messages/{$message->friendly_id}/resume")->assertForbidden();
    $this->withToken($otherToken)->postJson("/api/v1/whatsapp/messages/{$message->friendly_id}/cancel")->assertForbidden();
    $this->withToken($otherToken)->deleteJson("/api/v1/whatsapp/messages/{$message->friendly_id}")->assertForbidden();

    // Sending through someone else's account is refused like on the web.
    $this->withToken($otherToken)
        ->postJson('/api/v1/whatsapp/send-now', ($this->input)())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account']);

    expect($message->fresh()->trashed())->toBeFalse()
        ->and($message->recurrence->fresh()->is_paused)->toBeFalse();

    // Without the module, nothing at all.
    app('auth')->forgetGuards();
    $outsider = User::factory()->create()->createToken('iPad')->plainTextToken;

    $this->withToken($outsider)->getJson('/api/v1/whatsapp/accounts')->assertForbidden();
    $this->withToken($outsider)->getJson('/api/v1/whatsapp/deliveries')->assertForbidden();
});

test('api schedule message validates like web', function () {
    $invalid = ($this->input)([
        'targets' => [],
        'numbers' => ['12345'],
        'body' => 'Hi {% recipient.unknown %}',
        'recurrence' => ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => '2026-10-01T09:00'],
    ]);

    $apiErrors = $this->withToken($this->token)
        ->postJson('/api/v1/whatsapp/messages', $invalid)
        ->assertUnprocessable()
        ->json('errors');

    $webErrors = Livewire::actingAs($this->owner)
        ->test(MessageForm::class)
        ->set('account', $invalid['account'])
        ->set('selectedTargets', $invalid['targets'])
        ->set('numbers', $invalid['numbers'])
        ->set('body', $invalid['body'])
        ->call('save')
        ->errors()
        ->toArray();

    expect(array_keys($apiErrors))->toBe(array_keys($webErrors))
        ->and($apiErrors)->toHaveKeys(['numbers.0', 'body']);

    // A past date fails under `recurrence.*`, as on the web.
    $this->withToken($this->token)
        ->postJson('/api/v1/whatsapp/messages', ($this->input)(['recurrence' => ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => '2026-10-01T09:00']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence.starts_at']);

    expect(WhatsappMessage::query()->count())->toBe(0);

    // A valid one is scheduled, 09:00 in São Paulo.
    $created = $this->withToken($this->token)
        ->postJson('/api/v1/whatsapp/messages', ($this->input)(['numbers' => ['+55 11 98888-7777']]))
        ->assertCreated()
        ->assertJsonPath('data.account.friendly_id', $this->account->friendly_id)
        ->assertJsonPath('data.state', 'active')
        ->assertJsonPath('data.recurrence.frequency', 'once')
        ->assertJsonPath('data.recurrence.next_run_at', '2026-10-06T12:00:00+00:00')
        ->assertJsonCount(3, 'data.targets')
        ->assertJsonPath('data.targets.2.phone_number', '+5511988887777')
        ->assertJsonPath('data.targets.2.is_synced', false);

    $messageId = $created->json('data.friendly_id');

    $this->withToken($this->token)
        ->patchJson("/api/v1/whatsapp/messages/{$messageId}", ($this->input)([
            'targets' => [$this->ana->friendly_id],
            'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '08:00'],
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data.targets')
        ->assertJsonPath('data.recurrence.frequency', 'daily');

    // A recurring message is paused and resumed, not cancelled.
    $this->withToken($this->token)
        ->postJson("/api/v1/whatsapp/messages/{$messageId}/cancel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);

    $this->withToken($this->token)->postJson("/api/v1/whatsapp/messages/{$messageId}/pause")->assertOk()->assertJsonPath('data.state', 'paused');
    $this->withToken($this->token)->postJson("/api/v1/whatsapp/messages/{$messageId}/resume")->assertOk()->assertJsonPath('data.state', 'active');

    // A `once` message is cancelled before its time.
    $onceId = $this->withToken($this->token)->postJson('/api/v1/whatsapp/messages', ($this->input)())->assertCreated()->json('data.friendly_id');

    $this->withToken($this->token)
        ->postJson("/api/v1/whatsapp/messages/{$onceId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled')
        ->assertJsonPath('data.cancelled_at', '2026-10-05T12:00:00+00:00');

    $this->withToken($this->token)->deleteJson("/api/v1/whatsapp/messages/{$messageId}")->assertNoContent();
    $this->withToken($this->token)->getJson("/api/v1/whatsapp/messages/{$messageId}")->assertNotFound();
});

test('api send now creates a manual delivery', function () {
    Queue::fake();

    $this->withToken($this->token)
        ->postJson('/api/v1/whatsapp/send-now', ['account' => $this->account->friendly_id, 'targets' => [], 'body' => 'Hi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['targets']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/whatsapp/send-now', [
            'account' => $this->account->friendly_id,
            'targets' => [$this->ana->friendly_id],
            'body' => 'Hi {% recipient.name %}, dinner at 8!',
        ])
        ->assertAccepted()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.source', WhatsappDeliverySource::MANUAL)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.body', 'Hi Ana, dinner at 8!')
        ->assertJsonPath('data.0.message_id', null)
        ->assertJsonPath('data.0.target.friendly_id', $this->ana->friendly_id);

    $delivery = WhatsappDelivery::query()->sole();

    expect($delivery->whatsapp_message_id)->toBeNull()
        ->and($delivery->triggered_by_id)->toBe($this->owner->id)
        ->and($delivery->whatsapp_delivery_source_id)->toBe(WhatsappDeliverySource::idFor(WhatsappDeliverySource::MANUAL))
        ->and(WhatsappMessage::query()->count())->toBe(0)
        ->and($response->json('data.0.account.friendly_id'))->toBe($this->account->friendly_id);

    Queue::assertPushed(DeliverWhatsappMessage::class, 1);

    // It shows in the history.
    $this->withToken($this->token)
        ->getJson('/api/v1/whatsapp/deliveries')
        ->assertOk()
        ->assertJsonPath('data.0.body', 'Hi Ana, dinner at 8!')
        ->assertJsonPath('meta.total', 1);
});
