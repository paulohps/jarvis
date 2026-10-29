<?php

use App\Actions\Recurrences\PauseRecurrence;
use App\Actions\Recurrences\ResumeRecurrence;
use App\Actions\Whatsapp\CancelWhatsappMessage;
use App\Actions\Whatsapp\DeleteWhatsappMessage;
use App\Actions\Whatsapp\ScheduleWhatsappMessage;
use App\Actions\Whatsapp\UpdateWhatsappMessage;
use App\Jobs\RunScheduledSlot;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'America/Sao_Paulo']);
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

    $this->errorsOf = function (callable $call): array {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };
});

test('a once message is scheduled with its targets', function () {
    $message = app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)());

    expect($message->user_id)->toBe($this->owner->id)
        ->and($message->whatsapp_account_id)->toBe($this->account->id)
        ->and($message->body)->toBe('Hi {% recipient.name %}!')
        ->and($message->targets()->pluck('whatsapp_targets.id')->sort()->values()->all())->toBe([$this->ana->id, $this->family->id])
        ->and($message->isOnce())->toBeTrue()
        // 09:00 in São Paulo.
        ->and($message->recurrence->next_run_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00');

    // A recurring one gets its next run from the shared engine.
    $recurring = app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)([
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '08:00'],
    ]));

    expect($recurring->recurrence->frequency->slug)->toBe(RecurrenceFrequency::DAILY)
        ->and($recurring->recurrence->next_run_at->toIso8601String())->toBe('2026-10-06T11:00:00+00:00');
});

test('scheduling requires an open account owned by the user', function () {
    $schedule = app(ScheduleWhatsappMessage::class);

    $closed = WhatsappAccount::factory()->closed()->for($this->owner)->create(['nickname' => 'Old phone']);
    $closedTarget = WhatsappTarget::factory()->create(['whatsapp_account_id' => $closed->id]);

    expect(($this->errorsOf)(fn () => $schedule($this->owner, ($this->input)(['account' => $closed->friendly_id, 'targets' => [$closedTarget->friendly_id]]))))
        ->toHaveKey('account');

    $someoneElse = WhatsappAccount::factory()->open()->create();
    $theirTarget = WhatsappTarget::factory()->create(['whatsapp_account_id' => $someoneElse->id]);

    expect(($this->errorsOf)(fn () => $schedule($this->owner, ($this->input)(['account' => $someoneElse->friendly_id, 'targets' => [$theirTarget->friendly_id]]))))
        ->toHaveKey('account');

    expect(($this->errorsOf)(fn () => $schedule($this->owner, ($this->input)(['account' => 'wacc_missing']))))->toHaveKey('account');

    // Without the module the Action refuses outright.
    $outsider = User::factory()->create();
    $outsiderAccount = WhatsappAccount::factory()->open()->for($outsider)->create();

    expect(fn () => $schedule($outsider, ($this->input)(['account' => $outsiderAccount->friendly_id, 'targets' => [], 'numbers' => ['+5511900000000']])))
        ->toThrow(AuthorizationException::class);

    expect(WhatsappMessage::query()->count())->toBe(0);

    // Targets and a valid body are required too.
    $errors = ($this->errorsOf)(fn () => $schedule($this->owner, ($this->input)(['targets' => [], 'body' => 'Hi {% invoice.number %'])));

    expect($errors)->toHaveKeys(['body'])
        ->and(($this->errorsOf)(fn () => $schedule($this->owner, ($this->input)(['targets' => []]))))->toHaveKey('targets');
});

test('once message in the past is rejected', function () {
    expect(($this->errorsOf)(fn () => app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)([
        'recurrence' => ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => '2026-10-05T08:00'],
    ]))))->toHaveKey('recurrence.starts_at');

    expect(WhatsappMessage::query()->count())->toBe(0);
});

test('manual e164 number creates an unsynced target', function () {
    $message = app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)([
        'targets' => [],
        'numbers' => ['+55 (11) 98888-7777'],
    ]));

    $target = $message->targets()->sole();

    expect($target->jid)->toBe('5511988887777@s.whatsapp.net')
        ->and($target->is_synced)->toBeFalse()
        ->and($target->name)->toBeNull()
        ->and($target->type->slug)->toBe(WhatsappTargetType::CONTACT)
        ->and($target->whatsapp_account_id)->toBe($this->account->id);

    // Typing a known number reuses its target.
    $again = app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)(['targets' => [], 'numbers' => ['+5511988887777']]));

    expect($again->targets()->sole()->is($target))->toBeTrue()
        ->and(WhatsappTarget::query()->where('jid', '5511988887777@s.whatsapp.net')->count())->toBe(1);

    // A number not in E.164 is refused.
    expect(($this->errorsOf)(fn () => app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)(['targets' => [], 'numbers' => ['11988887777']]))))
        ->toHaveKey('numbers.0');
});

test('targets from another account are rejected', function () {
    $other = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $foreign = WhatsappTarget::factory()->create(['whatsapp_account_id' => $other->id]);

    $errors = ($this->errorsOf)(fn () => app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)([
        'targets' => [$this->ana->friendly_id, $foreign->friendly_id],
    ])));

    expect($errors)->toHaveKey('targets')
        ->and(WhatsappMessage::query()->count())->toBe(0);
});

test('cancelled once message is not sent', function () {
    $message = app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)());

    $cancelled = app(CancelWhatsappMessage::class)($this->owner, $message);

    expect($cancelled->cancelled_at?->toIso8601String())->toBe('2026-10-05T12:00:00+00:00')
        ->and($message->recurrence()->sole()->next_run_at)->toBeNull();

    // Nothing runs at its time, even for a slot dispatched before.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:01', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();
    RunScheduledSlot::dispatchSync($message->recurrence()->sole(), CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));

    expect(WhatsappDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();

    // A cancelled message is not edited any more.
    expect(($this->errorsOf)(fn () => app(UpdateWhatsappMessage::class)($this->owner, $message->refresh(), ($this->input)())))->toHaveKey('message');
});

test('cancelling a message already sent is an error', function () {
    $message = app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)());

    WhatsappDelivery::factory()->scheduled($message, CarbonImmutable::parse('2026-10-06 12:00', 'UTC'))->sent()->create();

    expect(($this->errorsOf)(fn () => app(CancelWhatsappMessage::class)($this->owner, $message->refresh())))->toHaveKey('message')
        ->and($message->refresh()->cancelled_at)->toBeNull();

    // Once its time came, it is too late as well.
    $late = app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)());
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));

    expect(($this->errorsOf)(fn () => app(CancelWhatsappMessage::class)($this->owner, $late)))->toHaveKey('message');
    $this->travelBack();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    // A recurring message is paused and resumed, not cancelled.
    $recurring = app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)([
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '08:00'],
    ]));

    expect(($this->errorsOf)(fn () => app(CancelWhatsappMessage::class)($this->owner, $recurring)))->toHaveKey('message');

    app(PauseRecurrence::class)($recurring->recurrence);
    expect($recurring->recurrence->refresh()->is_paused)->toBeTrue();

    app(ResumeRecurrence::class)($recurring->recurrence);
    expect($recurring->recurrence->refresh()->is_paused)->toBeFalse();
});

test('only the author edits cancels or deletes a message', function () {
    $message = app(ScheduleWhatsappMessage::class)($this->owner, ($this->input)());
    $intruder = User::factory()->withModules([Module::WHATSAPP])->create();
    $admin = User::factory()->superAdmin()->create();

    foreach ([$intruder, $admin] as $user) {
        expect(fn () => app(UpdateWhatsappMessage::class)($user, $message, ($this->input)()))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CancelWhatsappMessage::class)($user, $message))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteWhatsappMessage::class)($user, $message))->toThrow(AuthorizationException::class);
    }

    // The author edits it: the targets and the schedule are replaced.
    $updated = app(UpdateWhatsappMessage::class)($this->owner, $message, ($this->input)([
        'targets' => [$this->family->friendly_id],
        'body' => 'Updated',
        'recurrence' => ['frequency' => RecurrenceFrequency::WEEKLY, 'weekdays' => ['monday'], 'time_of_day' => '07:30'],
    ]));

    expect($updated->body)->toBe('Updated')
        ->and($updated->targets()->pluck('whatsapp_targets.id')->all())->toBe([$this->family->id])
        ->and($updated->recurrence->frequency->slug)->toBe(RecurrenceFrequency::WEEKLY);

    // Deleting pauses the schedule and soft-deletes it.
    app(DeleteWhatsappMessage::class)($this->owner, $updated);

    expect($updated->refresh()->trashed())->toBeTrue()
        ->and($updated->recurrence->is_paused)->toBeTrue();
});
