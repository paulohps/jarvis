<?php

use App\Actions\Chats\ScheduleChatMessage;
use App\Actions\Chats\SendChatScheduledMessage;
use App\Ai\Agents\ChatReplyAgent;
use App\Events\ChatConversationUpdated;
use App\Livewire\Chats\ChatsPage;
use App\Livewire\Chats\ScheduledMessages;
use App\Livewire\Chats\SendingHistory;
use App\Models\AiProviderConnection;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\ChatScheduledDelivery;
use App\Models\ChatScheduledMessage;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\SlackMessage;
use App\Models\SlackUser;
use App\Models\User;
use App\Models\Weekday;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Fixtures\Slack\FakeSlackApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    // A Wednesday.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'UTC'));
    Event::fake([ChatConversationUpdated::class]);
    FakeSlackApi::install();
    AiProviderConnection::factory()->active()->create();

    $this->workspace = Workspace::factory()->create();
    $this->owner = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create(['timezone' => 'UTC']);
    $slack = ChatProvider::idFor(ChatProvider::SLACK);
    $this->workspace->chatProviders()->sync([$slack => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->memberships()->sole()->chatProviders()->sync([$slack => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->forgetMemberships();

    $account = SlackAccount::factory()->for($this->owner)->create(['team_id' => FakeSlackApi::TEAM_ID, 'slack_user_id' => FakeSlackApi::USER_ID, 'team_domain' => 'acme']);
    SlackUser::factory()->for($account, 'account')->create(['slack_user_id' => FakeSlackApi::ANA_ID, 'name' => 'ana', 'real_name' => 'Ana Lima', 'display_name' => 'Ana']);
    $dm = SlackConversation::factory()->for($account, 'account')->directWith(FakeSlackApi::ANA_ID)->create(['channel_id' => FakeSlackApi::DM_ID]);
    SlackMessage::factory()->for($dm, 'conversation')->create(['ts' => '1700000100.000100', 'slack_user_id' => FakeSlackApi::USER_ID, 'text' => 'Bom dia, Ana! Foco total hoje.', 'sent_at' => now()->subDay()]);
    $this->ana = ChatConversation::factory()->for($this->owner)->forSlack($dm)->direct()->create(['name' => 'Ana Lima', 'last_message_at' => now()->subDay()]);
});

test('a message written by the AI goes out on a recurring schedule, each time anew, and each sending is kept', function () {
    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->call('startSchedule')
        ->set('scheduleWithAi', true)
        ->set('schedulePrompt', 'Deseje bom dia com uma frase motivacional diferente')
        ->set('scheduleRecurrence.frequency', RecurrenceFrequency::WEEKLY)
        ->set('scheduleRecurrence.weekdays', [Weekday::THURSDAY, Weekday::FRIDAY])
        ->set('scheduleRecurrence.time_of_day', '08:30')
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertSet('showSchedule', false)
        ->assertSeeHtml('data-chat-scheduled=');

    $scheduled = ChatScheduledMessage::query()->sole();
    expect($scheduled)
        ->body->toBeNull()
        ->ai_prompt->toBe('Deseje bom dia com uma frase motivacional diferente')
        ->send_at->toBeNull()
        ->and($scheduled->repeats())->toBeTrue()
        ->and($scheduled->nextSendAt()->toDateTimeString())->toBe('2026-10-15 08:30:00');

    ChatReplyAgent::fake(['Bom dia, Ana! Hoje é dia de fazer acontecer.'])->preventStrayPrompts();

    $this->travelTo(CarbonImmutable::parse('2026-10-15 08:31', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();
    // The same occurrence again sends nothing.
    app(SendChatScheduledMessage::class)($scheduled->fresh(), CarbonImmutable::parse('2026-10-15 08:30', 'UTC'));

    ChatReplyAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('Deseje bom dia com uma frase motivacional diferente')
        && $prompt->contains('Bom dia, Ana! Foco total hoje.')
        && $prompt->contains('say it anew'));
    expect(FakeSlackApi::called('chat.postMessage', ['text' => 'Bom dia, Ana! Hoje é dia de fazer acontecer.']))->toBeTrue();

    $delivery = ChatScheduledDelivery::query()->sole();
    expect($delivery)
        ->status->toBe(ChatScheduledDelivery::SENT)
        ->body->toBe('Bom dia, Ana! Hoje é dia de fazer acontecer.')
        ->slot_at->toDateTimeString()->toBe('2026-10-15 08:30:00')
        ->conversation_name->toBe('Ana Lima')
        ->and($scheduled->fresh())->sent_at->toBeNull()->isPending()->toBeTrue()
        ->and($scheduled->fresh()->nextSendAt()->toDateTimeString())->toBe('2026-10-16 08:30:00');

    Livewire::actingAs($this->owner)
        ->test(ScheduledMessages::class)
        ->assertSeeHtml('data-chat-scheduled="'.$scheduled->friendly_id.'"')
        ->assertSeeHtml('data-chat-scheduled-repeats')
        ->assertSee('Deseje bom dia');

    Livewire::actingAs($this->owner)
        ->test(SendingHistory::class)
        ->assertSeeHtml('data-chat-delivery="'.$delivery->friendly_id.'"')
        ->assertSee('Hoje é dia de fazer acontecer.');
});

test('a message once goes out a single time and leaves the scheduled ones', function () {
    $this->actingAs($this->owner)->get(route('chats.scheduled'))->assertOk()->assertSee('Nothing scheduled');

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->set('text', 'Reunião às 10h')
        ->call('startSchedule')
        ->assertSet('scheduleBody', 'Reunião às 10h')
        ->assertSet('scheduleRecurrence.starts_at', '2026-10-15T09:00')
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertSet('text', '');

    $scheduled = ChatScheduledMessage::query()->sole();

    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:01', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect(FakeSlackApi::called('chat.postMessage', ['text' => 'Reunião às 10h']))->toBeTrue()
        ->and($scheduled->fresh())->sent_at->not->toBeNull()->isPending()->toBeFalse()
        ->and(ChatScheduledDelivery::query()->sole()->status)->toBe(ChatScheduledDelivery::SENT);

    $this->actingAs($this->owner)->get(route('chats.scheduled'))->assertSee('Nothing scheduled');
    $this->actingAs($this->owner)->get(route('chats.history'))->assertOk()->assertSee('Reunião às 10h');
});

test('what is missing or in the past is said where it is typed', function () {
    $screen = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->call('startSchedule')
        ->call('schedule')
        ->assertHasErrors(['scheduleBody' => 'required'])
        ->set('scheduleBody', 'Oi')
        ->set('scheduleRecurrence.starts_at', '2026-10-14T11:00')
        ->call('schedule')
        ->assertHasErrors('scheduleRecurrence.starts_at')
        ->set('scheduleWithAi', true)
        ->call('schedule')
        ->assertHasErrors(['schedulePrompt' => 'required']);

    AiProviderConnection::query()->delete();

    $screen->set('schedulePrompt', 'Um bom dia')
        ->set('scheduleRecurrence.starts_at', '2026-10-15T09:00')
        ->call('schedule')
        ->assertHasErrors('schedulePrompt');

    expect(ChatScheduledMessage::query()->count())->toBe(0);
});

test('a sending the channel refuses is kept as failed, with why, and a repeating one goes on', function () {
    FakeSlackApi::install(['chat.postMessage' => ['ok' => false, 'error' => 'channel_not_found']]);
    $scheduled = app(ScheduleChatMessage::class)($this->owner, $this->ana, [
        'body' => 'Lembrete diário',
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '13:00'],
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:01', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    $delivery = ChatScheduledDelivery::query()->sole();
    expect($delivery->status)->toBe(ChatScheduledDelivery::FAILED)
        ->and($delivery->error)->not->toBeEmpty()
        ->and($scheduled->fresh())->failed_at->toBeNull()->isPending()->toBeTrue();

    Livewire::actingAs($this->owner)->test(SendingHistory::class)->assertSeeHtml('data-chat-delivery-error');
});

test('a scheduled message cancelled from the list stops repeating', function () {
    $scheduled = app(ScheduleChatMessage::class)($this->owner, $this->ana, [
        'body' => 'Lembrete diário',
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '13:00'],
    ]);

    Livewire::actingAs($this->owner)
        ->test(ScheduledMessages::class)
        ->call('cancel', $scheduled->friendly_id)
        ->assertSet('notice', 'Scheduled message cancelled.')
        ->assertDontSeeHtml('data-chat-scheduled="'.$scheduled->friendly_id.'"');

    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:01', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($scheduled->fresh()->recurrence->is_paused)->toBeTrue()
        ->and(ChatScheduledDelivery::query()->count())->toBe(0)
        ->and(FakeSlackApi::called('chat.postMessage'))->toBeFalse();

    $stranger = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create();

    Livewire::actingAs($stranger)->test(ScheduledMessages::class)->call('cancel', $scheduled->friendly_id)->assertNotFound();
});

test('a message scheduled before the recurrences still goes out at its time, into the history', function () {
    $legacy = ChatScheduledMessage::factory()->due()->create(['chat_conversation_id' => $this->ana->id, 'user_id' => $this->owner->id, 'body' => 'Do tempo antigo']);

    $this->artisan('chats:send-scheduled')->assertSuccessful();

    expect($legacy->fresh()->sent_at)->not->toBeNull()
        ->and(ChatScheduledDelivery::query()->sole())->body->toBe('Do tempo antigo')->status->toBe(ChatScheduledDelivery::SENT);
});

test('the API schedules a repeating message written by the AI', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson(route('api.chats.conversations.schedule', $this->ana->friendly_id), [
        'ai_prompt' => 'Um bom dia diferente',
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '08:00'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.ai_prompt', 'Um bom dia diferente')
        ->assertJsonPath('data.body', null)
        ->assertJsonPath('data.frequency', RecurrenceFrequency::DAILY)
        ->assertJsonPath('data.send_at', '2026-10-15T08:00:00+00:00');

    $this->postJson(route('api.chats.conversations.schedule', $this->ana->friendly_id), ['body' => 'Oi', 'send_at' => '2026-10-15 10:00'])
        ->assertCreated()
        ->assertJsonPath('data.frequency', RecurrenceFrequency::ONCE)
        ->assertJsonPath('data.send_at', '2026-10-15T10:00:00+00:00');

    $this->postJson(route('api.chats.conversations.schedule', $this->ana->friendly_id), ['body' => 'Oi', 'send_at' => '2026-10-13 10:00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('send_at');
});
