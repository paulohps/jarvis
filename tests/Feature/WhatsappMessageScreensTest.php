<?php

use App\Actions\Whatsapp\SendWhatsappNow;
use App\Livewire\Whatsapp\DeliveryHistory;
use App\Livewire\Whatsapp\MessageForm;
use App\Livewire\Whatsapp\MessageList;
use App\Models\AiProviderConnection;
use App\Models\DeliveryStatus;
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
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['name' => 'Paulo', 'timezone' => 'America/Sao_Paulo']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal']);
    $this->ana = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana']);
    $this->bruno = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bruno']);
});

test('send now creates a manual delivery without schedule', function () {
    $deliveries = app(SendWhatsappNow::class)($this->owner, [
        'account' => $this->account->friendly_id,
        'targets' => [$this->ana->friendly_id],
        'numbers' => ['+5511977776666'],
        'body' => 'Oi {% recipient.name %}',
    ]);

    expect($deliveries)->toHaveCount(2)
        ->and(WhatsappMessage::query()->count())->toBe(0);

    $deliveries->each(function (WhatsappDelivery $delivery): void {
        $delivery->refresh();

        expect($delivery->source->slug)->toBe(WhatsappDeliverySource::MANUAL)
            ->and($delivery->whatsapp_message_id)->toBeNull()
            ->and($delivery->scheduled_for)->toBeNull()
            ->and($delivery->triggered_by_id)->toBe($this->owner->id)
            ->and($delivery->status->slug)->toBe(DeliveryStatus::SENT);
    });

    expect($deliveries->first()->body)->toBe('Oi Ana')
        ->and($deliveries->last()->body)->toBe('Oi')
        ->and($deliveries->last()->target->is_synced)->toBeFalse();
    Http::assertSentCount(2);

    // The form sends what it holds, saved or not, and schedules nothing.
    Livewire::actingAs($this->owner)
        ->test(MessageForm::class)
        ->assertSet('account', $this->account->friendly_id)
        ->call('addTarget', $this->bruno->friendly_id)
        ->set('body', 'Hello {% recipient.name %}')
        ->assertSee('Hello Ana')
        ->call('sendNow')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-message-status');

    expect(WhatsappDelivery::query()->latest('id')->first()->body)->toBe('Hello Bruno')
        ->and(WhatsappMessage::query()->count())->toBe(0);

    // The quick action of the list sends a saved message without touching its schedule.
    $message = WhatsappMessage::factory()->recurring()->create(['user_id' => $this->owner->id, 'whatsapp_account_id' => $this->account->id, 'body' => 'Quick {% recipient.name %}']);
    $message->targets()->attach($this->ana);
    $nextRun = $message->recurrence->next_run_at;

    Livewire::actingAs($this->owner)
        ->test(MessageList::class)
        ->assertSeeHtml('data-message-quick-send="'.$message->friendly_id.'"')
        ->call('sendNow', $message->friendly_id)
        ->assertSeeHtml('data-message-status');

    $quick = WhatsappDelivery::query()->latest('id')->first();

    expect($quick->body)->toBe('Quick Ana')
        ->and($quick->whatsapp_message_id)->toBeNull()
        ->and($quick->source->slug)->toBe(WhatsappDeliverySource::MANUAL)
        ->and($message->recurrence->refresh()->next_run_at->equalTo($nextRun))->toBeTrue();
});

test('history lists only the users own accounts', function () {
    $mine = WhatsappDelivery::factory()->sent()->create([
        'whatsapp_account_id' => $this->account->id,
        'whatsapp_target_id' => $this->ana->id,
        'body' => 'My own message to Ana',
    ]);
    WhatsappDelivery::factory()->failed()->create([
        'whatsapp_account_id' => $this->account->id,
        'whatsapp_target_id' => $this->bruno->id,
        'body' => 'This one did not go',
        'error_message' => 'Connection Closed',
    ]);

    $someoneElse = User::factory()->withModules([Module::WHATSAPP])->create();
    $theirAccount = WhatsappAccount::factory()->open()->for($someoneElse)->create(['nickname' => 'Their phone']);
    WhatsappDelivery::factory()->sent()->create(['whatsapp_account_id' => $theirAccount->id, 'body' => 'Somebody else message']);

    $this->actingAs($this->owner)->get(route('whatsapp.history.index'))->assertOk();

    Livewire::actingAs($this->owner)
        ->test(DeliveryHistory::class)
        ->assertSee('My own message to Ana')
        ->assertSee('Personal')
        ->assertSee('Ana')
        ->assertSee('This one did not go')
        ->assertSee('Connection Closed')
        ->assertSeeHtml('data-delivery-row="'.$mine->id.'"')
        ->assertDontSee('Somebody else message')
        ->assertDontSee('Their phone');

    Livewire::actingAs($someoneElse)->test(DeliveryHistory::class)->assertSee('Somebody else message')->assertDontSee('My own message to Ana');

    // The super admin sees only their own as well.
    Livewire::actingAs(User::factory()->superAdmin()->create())->test(DeliveryHistory::class)->assertDontSee('My own message to Ana')->assertDontSee('Somebody else message');

    // Paginated, newest first.
    WhatsappDelivery::factory()->count(DeliveryHistory::PER_PAGE)->sent()->create(['whatsapp_account_id' => $this->account->id, 'whatsapp_target_id' => $this->ana->id, 'created_at' => now()->addMinute()]);

    expect(Livewire::actingAs($this->owner)->test(DeliveryHistory::class)->instance()->deliveries->total())->toBe(DeliveryHistory::PER_PAGE + 2);
});

test('the form schedules a message with a searched target and a typed number', function () {
    $this->actingAs($this->owner)->get(route('whatsapp.messages.create'))->assertOk();

    Livewire::actingAs($this->owner)
        ->test(MessageForm::class)
        ->set('targetSearch', 'bru')
        ->assertSeeHtml('data-target-option="'.$this->bruno->friendly_id.'"')
        ->assertDontSeeHtml('data-target-option="'.$this->ana->friendly_id.'"')
        ->call('addTarget', $this->bruno->friendly_id)
        ->set('newNumber', '11 99999')
        ->call('addNumber')
        ->assertHasErrors('newNumber')
        ->set('newNumber', '+55 11 96666-5555')
        ->call('addNumber')
        ->assertSet('numbers', ['+5511966665555'])
        ->set('body', 'Hi {% recipient.name %}')
        ->set('recurrence.frequency', RecurrenceFrequency::ONCE)
        ->set('recurrence.starts_at', '2026-10-05T08:00')
        ->call('save')
        ->assertHasErrors('recurrence.starts_at')
        ->set('recurrence.starts_at', '2026-10-07T10:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('whatsapp.messages.edit', WhatsappMessage::query()->sole()));

    $message = WhatsappMessage::query()->sole();

    expect($message->targets()->count())->toBe(2)
        ->and($message->recurrence->next_run_at->toIso8601String())->toBe('2026-10-07T13:00:00+00:00');

    // The saved message shows its state and next run, and is cancelled from there.
    Livewire::actingAs($this->owner)
        ->test(MessageForm::class, ['message' => $message])
        ->assertSeeHtml('data-message-state="active"')
        ->assertSeeHtml('data-message-cancel')
        ->call('cancel')
        ->assertSeeHtml('data-message-state="cancelled"');

    // Listed with its state; another user's list does not show it.
    Livewire::actingAs($this->owner)->test(MessageList::class)->assertSeeHtml('data-whatsapp-message="'.$message->friendly_id.'"');
    Livewire::actingAs(User::factory()->withModules([Module::WHATSAPP])->create())->test(MessageList::class)->assertDontSeeHtml('data-whatsapp-message="'.$message->friendly_id.'"');
});

test('only the author opens a message and recurring ones pause and resume', function () {
    $message = WhatsappMessage::factory()->recurring()->withTargets()->create(['user_id' => $this->owner->id, 'whatsapp_account_id' => $this->account->id]);
    $intruder = User::factory()->withModules([Module::WHATSAPP])->create();

    $this->actingAs($intruder)->get(route('whatsapp.messages.edit', $message))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('whatsapp.messages.index'))->assertForbidden();
    $this->actingAs($this->owner)->get(route('whatsapp.messages.edit', $message))->assertOk();
    $this->actingAs($this->owner)->get(route('whatsapp.messages.index'))->assertOk();

    Livewire::actingAs($this->owner)
        ->test(MessageForm::class, ['message' => $message])
        ->assertSeeHtml('data-message-send-now')
        ->call('pause')
        ->assertSeeHtml('data-message-state="paused"')
        ->call('resume')
        ->assertSeeHtml('data-message-state="active"');
});

test('a message the AI writes opens with its prompt, keeps it when saved, and goes back to a body', function () {
    AiProviderConnection::factory()->active()->create();
    $message = WhatsappMessage::factory()->recurring()->create([
        'user_id' => $this->owner->id,
        'whatsapp_account_id' => $this->account->id,
        'body' => '',
        'ai_prompt' => 'deseja bom dia',
    ]);
    $message->targets()->attach($this->ana);

    $form = Livewire::actingAs($this->owner)
        ->test(MessageForm::class, ['message' => $message])
        ->assertSet('withAi', true)
        ->assertSet('aiPrompt', 'deseja bom dia')
        ->assertDontSeeHtml('data-message-send-now')
        ->set('aiPrompt', '')
        ->call('save')
        ->assertHasErrors('aiPrompt')
        ->set('aiPrompt', 'deseja boa noite')
        ->call('save')
        ->assertHasNoErrors();

    expect($message->fresh()->ai_prompt)->toBe('deseja boa noite');

    $form->set('withAi', false)->set('body', 'Boa noite!')->call('save')->assertHasNoErrors();

    expect($message->fresh())->ai_prompt->toBeNull()->body->toBe('Boa noite!');
});
