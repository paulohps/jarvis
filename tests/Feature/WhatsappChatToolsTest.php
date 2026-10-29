<?php

use App\Actions\Whatsapp\SetWhatsappChatPriority;
use App\Ai\Agents\WhatsappReplyAgent;
use App\Exceptions\AiProviderNotConfigured;
use App\Jobs\WriteScheduledWhatsappMessage;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\Whatsapp\Chat;
use App\Models\AiProviderConnection;
use App\Models\DeliveryStatus;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Weekday;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP, Module::REMINDERS])->create(['timezone' => 'UTC']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();

    $this->chatWith = fn (string $name, array $attributes = []): WhatsappTarget => WhatsappTarget::factory()->contact()->create([
        'whatsapp_account_id' => $this->account->id,
        'name' => $name,
        'last_message_at' => now()->subHour(),
        ...$attributes,
    ]);
});

test('any number of chats stay pinned on top, the latest pinned first', function () {
    $chats = collect(['Ana', 'Bruno', 'Carla', 'Davi', 'Eva'])
        ->mapWithKeys(fn (string $name, int $index) => [$name => ($this->chatWith)($name, ['last_message_at' => now()->subMinutes(10 - $index)])]);

    $screen = Livewire::actingAs($this->owner)->test(Chat::class);

    foreach (['Ana', 'Bruno', 'Carla', 'Davi'] as $minutes => $name) {
        $this->travel(1)->minutes();
        $screen->call('togglePin', $chats[$name]->friendly_id);
    }

    expect($screen->instance()->chats->pluck('name')->all())->toBe(['Davi', 'Carla', 'Bruno', 'Ana', 'Eva']);

    $screen->call('togglePin', $chats['Carla']->friendly_id);

    expect($screen->instance()->chats->pluck('name')->all())->toBe(['Davi', 'Bruno', 'Ana', 'Eva', 'Carla'])
        ->and($chats['Carla']->fresh()->pinned_at)->toBeNull();
});

test('a chat gets a priority shown in the color the user set for it, and the list sorts by it on demand', function () {
    $ana = ($this->chatWith)('Ana', ['last_message_at' => now()->subMinutes(1)]);
    $bruno = ($this->chatWith)('Bruno', ['last_message_at' => now()->subMinutes(2)]);
    ($this->chatWith)('Carla', ['last_message_at' => now()->subMinutes(3)]);

    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $bruno->friendly_id])
        ->call('editPriority')
        ->set('priority', '9')
        ->call('savePriority')
        ->assertHasErrors('priority')
        ->set('priority', '1')
        ->call('savePriority')
        ->assertHasNoErrors()
        ->assertSet('showPriority', false);

    expect($bruno->fresh()->priority)->toBe(1)
        ->and($bruno->fresh()->priorityHex())->toBe(WhatsappTarget::PRIORITY_COLORS['red']);

    // The user sets the color of each priority once, in the profile, and every chat follows.
    $screen->call('editPriority')->assertSeeHtml('data-chat-priority-colors-link')->assertDontSeeHtml('data-profile-priority-colors');

    Livewire::actingAs($this->owner)
        ->test(ProfilePage::class, ['tab' => ProfilePage::TAB_PREFERENCES])
        ->assertSeeHtml('data-profile-priority-colors')
        ->assertSet('priorityColors.1', 'red')
        ->set('priorityColors.1', 'pink')
        ->set('priorityColors.2', 'violet')
        ->call('savePriorityColors')
        ->assertHasErrors('2')
        ->set('priorityColors.2', 'brown')
        ->call('savePriorityColors')
        ->assertHasNoErrors();

    expect($this->owner->fresh()->whatsappPriorityColors())->toBe([1 => 'pink', 2 => 'brown', 3 => 'yellow', 4 => 'green', 5 => 'blue'])
        ->and($bruno->fresh()->priorityHex())->toBe(WhatsappTarget::PRIORITY_COLORS['pink']);

    $screen->call('openChat', $ana->friendly_id)->call('editPriority')->set('priority', '3')->call('savePriority');

    expect($ana->fresh()->priorityHex())->toBe(WhatsappTarget::PRIORITY_COLORS['yellow'])
        ->and($screen->instance()->chats->pluck('name')->all())->toBe(['Ana', 'Bruno', 'Carla']);

    $screen->set('sort', Chat::SORT_PRIORITY)->assertSeeInIsland('data-chat-priority="'.$bruno->friendly_id.'"');
    unset($screen->instance()->chats);
    expect($screen->instance()->chats->pluck('name')->all())->toBe(['Bruno', 'Ana', 'Carla']);

    $screen->call('openChat', $bruno->friendly_id)->call('editPriority')->set('priority', '')->call('savePriority');
    expect($bruno->fresh()->priority)->toBeNull()->and($bruno->fresh()->priorityHex())->toBeNull();
});

test('a reminder created from a message quotes it and shows in the chat', function () {
    $ana = ($this->chatWith)('Ana', ['jid' => '5511911110000@s.whatsapp.net']);
    $message = WhatsappChatMessage::factory()->for($ana, 'target')->create(['body' => 'Me manda o orçamento amanhã?']);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $ana->friendly_id])
        ->call('startReminder', $message->friendly_id)
        ->assertSet('reminderTitle', 'Answer Ana')
        ->set('reminderDueAt', '2026-10-07T09:00')
        ->call('createReminder')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-chat-reminder-item=');

    $reminder = Reminder::query()->sole();
    expect($reminder->title)->toBe('Answer Ana')
        ->and($reminder->note)->toContain('Ana (+5511911110000)')
        ->and($reminder->note)->toContain('“Me manda o orçamento amanhã?”')
        ->and($reminder->occurrences()->sole()->due_at->toIso8601ZuluString())->toBe('2026-10-07T09:00:00Z')
        ->and($ana->reminders()->sole()->pivot->whatsapp_chat_message_id)->toBe($message->id);
});

test('without the reminders module there is no reminder from a chat', function () {
    $user = User::factory()->withModules([Module::WHATSAPP])->create();
    $account = WhatsappAccount::factory()->open()->for($user)->create();
    $chat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $account->id, 'last_message_at' => now()]);

    Livewire::actingAs($user)
        ->test(Chat::class, ['chatId' => $chat->friendly_id])
        ->assertDontSeeHtml('data-chat-reminder-form')
        ->assertDontSeeHtml('wire:click="startReminder"')
        ->call('startReminder')
        ->set('reminderTitle', 'Ligar')
        ->call('createReminder')
        ->assertForbidden();

    expect(Reminder::query()->count())->toBe(0);
});

test('a message is scheduled to the chat, listed there and cancelled there', function () {
    $ana = ($this->chatWith)('Ana');

    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $ana->friendly_id])
        ->set('text', 'Bom dia!')
        ->call('startSchedule')
        ->assertSet('scheduleRecurrence.frequency', RecurrenceFrequency::ONCE)
        ->set('scheduleRecurrence.starts_at', '2026-10-01T09:00')
        ->call('schedule')
        ->assertHasErrors('scheduleRecurrence.starts_at')
        ->set('scheduleRecurrence.starts_at', '2026-10-07T09:00')
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertSet('text', '')
        ->assertSeeHtml('data-chat-scheduled=');

    $message = WhatsappMessage::query()->sole();
    expect($message->body)->toBe('Bom dia!')
        ->and($message->targets()->sole()->is($ana))->toBeTrue()
        ->and($message->recurrence->next_run_at->toIso8601ZuluString())->toBe('2026-10-07T09:00:00Z');

    $screen->call('cancelScheduled', $message->friendly_id)
        ->assertSet('notice', 'Scheduled message cancelled.')
        ->assertDontSeeHtml('data-chat-scheduled=');

    expect($message->fresh()->cancelled_at)->not->toBeNull();
});

test('a message is scheduled to the chat on a repeating schedule, and edited on its page', function () {
    $ana = ($this->chatWith)('Ana');

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $ana->friendly_id])
        ->set('text', 'Bom dia!')
        ->call('startSchedule')
        ->set('scheduleRecurrence.frequency', RecurrenceFrequency::WEEKLY)
        ->set('scheduleRecurrence.time_of_day', '10:00')
        ->call('schedule')
        ->assertHasErrors('scheduleRecurrence.weekdays')
        ->set('scheduleRecurrence.weekdays', [Weekday::MONDAY, Weekday::WEDNESDAY])
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-chat-scheduled=')
        ->assertSeeHtml('whatsapp/messages/');

    $message = WhatsappMessage::query()->sole();
    expect($message->isOnce())->toBeFalse()
        ->and($message->recurrence->next_run_at->toIso8601ZuluString())->toBe('2026-10-07T10:00:00Z');
});

test('a message the AI writes is scheduled from a prompt, written from the chat at each run and sent', function () {
    AiProviderConnection::factory()->active()->create();
    WhatsappReplyAgent::fake(['Bom dia, Ana! Hoje é dia de vencer.'])->preventStrayPrompts();
    Queue::fake([WriteScheduledWhatsappMessage::class]);
    $ana = ($this->chatWith)('Ana', ['jid' => '5511911110000@s.whatsapp.net']);
    WhatsappChatMessage::factory()->for($ana, 'target')->create(['body' => 'Ontem foi puxado', 'sent_at' => now()->subHour()]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $ana->friendly_id])
        ->set('text', 'rascunho em andamento')
        ->call('startSchedule')
        ->set('scheduleWithAi', true)
        ->set('scheduleRecurrence.frequency', RecurrenceFrequency::DAILY)
        ->set('scheduleRecurrence.time_of_day', '07:00')
        ->call('schedule')
        ->assertHasErrors('schedulePrompt')
        ->set('schedulePrompt', 'deseja bom dia com uma frase motivacional')
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertSet('text', 'rascunho em andamento')
        ->assertSeeHtml('data-chat-scheduled-ai');

    $message = WhatsappMessage::query()->sole();
    expect($message->ai_prompt)->toBe('deseja bom dia com uma frase motivacional')
        ->and($message->body)->toBe('');

    $slot = CarbonImmutable::parse('2026-10-07 07:00', 'UTC');
    $message->runScheduledSlot($slot);

    Queue::assertPushedOn('whatsapp', WriteScheduledWhatsappMessage::class, fn (WriteScheduledWhatsappMessage $job): bool => $job->target->is($ana) && $job->slot->equalTo($slot));

    $job = new WriteScheduledWhatsappMessage($message, $ana, $slot);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    WhatsappReplyAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('deseja bom dia com uma frase motivacional') && $prompt->contains('Ontem foi puxado'));

    $delivery = WhatsappDelivery::query()->sole();
    expect($delivery->body)->toBe('Bom dia, Ana! Hoje é dia de vencer.')
        ->and($delivery->whatsapp_message_id)->toBe($message->id)
        ->and($delivery->scheduled_for->equalTo($slot))->toBeTrue();
});

test('a run the AI could not write is logged as failed, with why', function () {
    $ana = ($this->chatWith)('Ana');
    $message = WhatsappMessage::factory()->recurring()->create([
        'user_id' => $this->owner->id,
        'whatsapp_account_id' => $this->account->id,
        'body' => '',
        'ai_prompt' => 'deseja bom dia',
    ]);
    $message->targets()->attach($ana);
    $slot = CarbonImmutable::parse('2026-10-07 09:00', 'UTC');

    (new WriteScheduledWhatsappMessage($message, $ana, $slot))->failed(new AiProviderNotConfigured);

    $delivery = WhatsappDelivery::query()->sole();
    expect($delivery->delivery_status_id)->toBe(DeliveryStatus::idFor(DeliveryStatus::FAILED))
        ->and($delivery->body)->toBe('deseja bom dia')
        ->and($delivery->error_message)->toBe('No AI is set up for WhatsApp replies. Ask the administrator to set one.');
});

test('without an AI set up, no message is scheduled for the AI to write', function () {
    $ana = ($this->chatWith)('Ana');

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $ana->friendly_id])
        ->call('startSchedule')
        ->set('scheduleWithAi', true)
        ->set('schedulePrompt', 'deseja bom dia')
        ->call('schedule')
        ->assertHasErrors('schedulePrompt');

    expect(WhatsappMessage::query()->count())->toBe(0);
});

test('nobody pins or prioritizes a chat of somebody else\'s number', function () {
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();
    $ana = ($this->chatWith)('Ana');

    Livewire::actingAs($stranger)
        ->test(Chat::class)
        ->call('togglePin', $ana->friendly_id)
        ->assertNotFound();

    expect(fn () => app(SetWhatsappChatPriority::class)($stranger, $ana, 1))
        ->toThrow(AuthorizationException::class);
});

test('the nickname, tags and priority open from the chat info panel, which gives way to their dialog', function () {
    $ana = ($this->chatWith)('Ana');

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $ana->friendly_id])
        ->call('openInfo', 'details')
        ->dispatch('whatsapp-chat-tool', tool: 'nickname')
        ->assertSet('showInfo', false)
        ->assertSet('showNickname', true)
        ->dispatch('whatsapp-chat-tool', tool: 'priority')
        ->assertSet('showPriority', true)
        ->dispatch('whatsapp-chat-tool', tool: 'sync')
        ->assertSet('showInfo', false);
});
