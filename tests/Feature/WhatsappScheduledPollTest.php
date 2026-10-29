<?php

use App\Actions\Whatsapp\ScheduleWhatsappMessage;
use App\Actions\Whatsapp\UpdateWhatsappMessage;
use App\Livewire\Whatsapp\Chat;
use App\Livewire\Whatsapp\ChatContent;
use App\Livewire\Whatsapp\MessageForm;
use App\Livewire\Whatsapp\MessageList;
use App\Models\DeliveryStatus;
use App\Models\Module;
use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['name' => 'Paulo', 'timezone' => 'America/Sao_Paulo']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal']);
    $this->ana = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net']);
    $this->family = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Family']);

    $this->sentPolls = fn () => collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/message/sendPoll/'))->map(fn (array $pair): Request => $pair[0])->values();
});

test('the form schedules a poll, which goes out as a poll at its time', function () {
    $form = Livewire::actingAs($this->owner)
        ->test(MessageForm::class)
        ->call('addTarget', $this->ana->friendly_id)
        ->call('addTarget', $this->family->friendly_id)
        ->set('withPoll', true)
        ->assertSeeHtml('data-message-poll-question')
        ->assertDontSeeHtml('data-message-with-ai')
        ->set('pollQuestion', 'Pizza ou sushi?')
        ->set('pollOptions', ['Pizza', 'pizza'])
        ->call('save')
        ->assertHasErrors('pollOptions.1');

    expect(WhatsappMessage::query()->count())->toBe(0);

    $form->set('pollOptions', ['Pizza', 'Sushi'])
        ->call('addPollOption')
        ->set('pollOptions.2', '  ')
        ->set('pollMultiple', true)
        ->call('save')
        ->assertHasNoErrors();

    $message = WhatsappMessage::query()->sole();

    // The blank option is dropped; the question is the body the lists show.
    expect($message->poll)->toBe(['name' => 'Pizza ou sushi?', 'options' => ['Pizza', 'Sushi'], 'selectable_count' => 0])
        ->and($message->body)->toBe('Pizza ou sushi?')
        ->and($message->sendsPoll())->toBeTrue()
        ->and($message->recurrence->next_run_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00');

    // Opened again, the form shows the poll.
    Livewire::actingAs($this->owner)
        ->test(MessageForm::class, ['message' => $message])
        ->assertSet('withPoll', true)
        ->assertSet('pollQuestion', 'Pizza ou sushi?')
        ->assertSet('pollOptions', ['Pizza', 'Sushi'])
        ->assertSet('pollMultiple', true);

    Livewire::actingAs($this->owner)->test(MessageList::class)->assertSee('📊 Pizza ou sushi?');

    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    $deliveries = WhatsappDelivery::query()->with('status')->orderBy('whatsapp_target_id')->get();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->every(fn (WhatsappDelivery $delivery): bool => $delivery->status->slug === DeliveryStatus::SENT
            && $delivery->body === 'Pizza ou sushi?'
            && $delivery->payload === ['poll' => ['name' => 'Pizza ou sushi?', 'options' => ['Pizza', 'Sushi'], 'selectable_count' => 0]]))->toBeTrue()
        ->and(($this->sentPolls)())->toHaveCount(2)
        ->and(($this->sentPolls)()->first()->data())->toBe(['number' => $this->ana->jid, 'name' => 'Pizza ou sushi?', 'selectableCount' => 0, 'values' => ['Pizza', 'Sushi']]);

    // A retried slot sends nothing twice.
    $message->runScheduledSlot(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));

    expect(($this->sentPolls)())->toHaveCount(2);
});

test('a poll goes without a prompt or a file, and turned off the message is a text again', function () {
    $input = [
        'account' => $this->account->friendly_id,
        'targets' => [$this->ana->friendly_id],
        'poll' => ['question' => 'Lunch?', 'options' => ['Yes', 'No']],
        'recurrence' => ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => '2026-10-06T09:00'],
    ];
    $schedule = app(ScheduleWhatsappMessage::class);

    expect(fn () => $schedule($this->owner, [...$input, 'file' => UploadedFile::fake()->image('a.png')]))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('file'));

    expect(fn () => $schedule($this->owner, [...$input, 'poll' => ['question' => '', 'options' => ['Yes']]]))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKeys(['poll.question', 'poll.options']));

    expect(WhatsappMessage::query()->count())->toBe(0);

    $message = $schedule($this->owner, $input);

    expect($message->poll['selectable_count'])->toBe(1);

    // Left out of an update, the poll stays; null makes it a text.
    $update = app(UpdateWhatsappMessage::class);
    $update($this->owner, $message, ['account' => $this->account->friendly_id, 'targets' => [$this->ana->friendly_id], 'recurrence' => $input['recurrence']]);

    expect($message->refresh()->poll['name'])->toBe('Lunch?');

    $update($this->owner, $message, ['account' => $this->account->friendly_id, 'targets' => [$this->ana->friendly_id], 'poll' => null, 'body' => 'Hi {% recipient.name %}', 'recurrence' => $input['recurrence']]);

    expect($message->refresh()->poll)->toBeNull()
        ->and($message->body)->toBe('Hi {% recipient.name %}');
});

test('send now sends the poll of the form and of the list', function () {
    Livewire::actingAs($this->owner)
        ->test(MessageForm::class)
        ->call('addTarget', $this->ana->friendly_id)
        ->set('withPoll', true)
        ->set('pollQuestion', 'Hoje?')
        ->set('pollOptions', ['Sim', 'Não'])
        ->call('sendNow')
        ->assertHasNoErrors();

    $message = WhatsappMessage::factory()->poll()->recurring()->create(['user_id' => $this->owner->id, 'whatsapp_account_id' => $this->account->id]);
    $message->targets()->attach($this->ana);

    Livewire::actingAs($this->owner)->test(MessageList::class)->call('sendNow', $message->friendly_id)->assertSet('error', null);

    expect(WhatsappMessage::query()->count())->toBe(1)
        ->and(($this->sentPolls)()->map(fn (Request $request): array => [$request['name'], $request['selectableCount'], $request['values']])->all())
        ->toBe([['Hoje?', 1, ['Sim', 'Não']], ['Lunch?', 1, ['Yes', 'No']]]);
});

test('the poll dialog of a chat schedules the poll to that chat', function () {
    $content = Livewire::actingAs($this->owner)->test(ChatContent::class, ['chatId' => $this->family->friendly_id]);

    $content->call('compose', 'poll')
        ->set('question', 'Churrasco sábado?')
        ->set('options', ['Sim', 'Não'])
        ->set('schedules', true)
        ->assertSeeHtml('data-chat-content-schedule')
        ->set('recurrence.starts_at', '2026-10-01T09:00')
        ->call('send')
        ->assertHasErrors('recurrence.starts_at');

    expect(WhatsappMessage::query()->count())->toBe(0);

    $content->set('recurrence.starts_at', '2026-10-09T18:00')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('show', false)
        ->assertDispatched('whatsapp-poll-scheduled')
        ->assertNotDispatched('whatsapp-content-sent');

    $message = WhatsappMessage::query()->with('targets')->sole();

    expect($message->poll['name'])->toBe('Churrasco sábado?')
        ->and($message->targets->modelKeys())->toBe([$this->family->id])
        ->and($message->recurrence->next_run_at->toIso8601String())->toBe('2026-10-09T21:00:00+00:00')
        ->and(($this->sentPolls)())->toHaveCount(0);

    // Opened again, the dialog sends now.
    $content->call('compose', 'poll')->assertSet('schedules', false);
});

test('the api schedules a poll and sends one now', function () {
    $token = $this->owner->createToken('iPhone')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/whatsapp/messages', [
            'account' => $this->account->friendly_id,
            'targets' => [$this->ana->friendly_id],
            'poll' => ['question' => 'Lunch?', 'options' => ['Yes', 'yes']],
            'recurrence' => ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => '2026-10-06T09:00'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['poll.options.1']);

    $this->withToken($token)
        ->postJson('/api/v1/whatsapp/messages', [
            'account' => $this->account->friendly_id,
            'targets' => [$this->ana->friendly_id],
            'poll' => ['question' => 'Lunch?', 'options' => ['Yes', 'No'], 'multiple' => true],
            'recurrence' => ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => '2026-10-06T09:00'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Lunch?')
        ->assertJsonPath('data.poll', ['question' => 'Lunch?', 'options' => ['Yes', 'No'], 'multiple' => true]);

    $this->withToken($token)
        ->postJson('/api/v1/whatsapp/send-now', [
            'account' => $this->account->friendly_id,
            'targets' => [$this->ana->friendly_id],
            'poll' => ['question' => 'Now?', 'options' => ['A', 'B']],
        ])
        ->assertSuccessful();

    expect(($this->sentPolls)()->sole()['name'])->toBe('Now?');
});

test('the chat lists a scheduled poll', function () {
    $message = WhatsappMessage::factory()->poll()
        ->has(Recurrence::factory()->once()->state(['next_run_at' => '2026-10-06 12:00:00']), 'recurrence')
        ->create(['user_id' => $this->owner->id, 'whatsapp_account_id' => $this->account->id]);
    $message->targets()->attach($this->ana);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->assertSeeHtml('data-chat-scheduled-poll')
        ->assertSee('Lunch?');
});
