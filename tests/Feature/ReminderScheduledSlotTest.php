<?php

use App\Actions\Reminders\CompleteReminderOccurrence;
use App\Actions\Reminders\CreateReminder;
use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WorkspaceMembership;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\Types\ReminderDue;
use App\Support\Localization;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->artisan('notifications:provision')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:00', 'UTC'));

    FakeEvolutionApi::install();

    $this->creator = User::factory()->withModules([Module::REMINDERS])->create([
        'timezone' => 'America/Sao_Paulo',
        'locale_id' => Locale::idFor(Locale::PT_BR),
    ]);
    $this->member = User::factory()->withModules([Module::REMINDERS])->create();
});

test('reminder due is provisioned with mail off by default', function () {
    expect(app(NotificationRegistry::class)->classFor(NotificationType::REMINDER_DUE))->toBe(ReminderDue::class)
        ->and(ReminderDue::module())->toBe(Module::REMINDERS);

    $type = NotificationType::query()->where('slug', NotificationType::REMINDER_DUE)->sole();
    $defaults = $type->typeChannels()->with('channel')->get()->mapWithKeys(fn ($typeChannel): array => [$typeChannel->channel->slug => $typeChannel->is_enabled_by_default])->sortKeys()->all();

    expect($type->is_active)->toBeTrue()
        ->and($defaults)->toBe([
            NotificationChannel::DATABASE => true,
            NotificationChannel::MAIL => false,
            NotificationChannel::PUSH => true,
            NotificationChannel::WHATSAPP => true,
        ]);

    foreach (ReminderDue::channels() as $channel) {
        foreach (Localization::SUPPORTED_LOCALES as $locale) {
            $template = NotificationTemplate::query()
                ->whereRelation('typeChannel', 'notification_type_id', $type->id)
                ->whereRelation('typeChannel', 'notification_channel_id', NotificationChannel::idFor($channel))
                ->where('locale_id', Locale::idFor($locale))
                ->sole();

            expect($template->title)->toContain('{% reminder.title %}')
                ->and($template->body)->toContain('{% reminder.due_at %}');
        }
    }
});

test('due reminder notifies the creator once', function () {
    // 09:00 in São Paulo is 12:00 UTC.
    $reminder = app(CreateReminder::class)($this->creator, ['title' => 'Tomar o remédio', 'note' => 'Caixa azul', 'due_at' => '2026-10-05T09:00']);
    $occurrence = $reminder->occurrences()->sole();

    // Not yet due: nothing is dispatched.
    $this->artisan('recurrences:dispatch')->assertSuccessful();
    expect($this->creator->notifications()->count())->toBe(0);

    // Within the minute it is due.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:20', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    $data = $this->creator->notifications()->sole()->data;

    expect($data['key'])->toBe(NotificationType::REMINDER_DUE)
        ->and($data['title'])->toBe('Lembrete: Tomar o remédio')
        ->and($data['body'])->toContain('vence em 5 de outubro de 2026 às 09:00')
        ->and($data['body'])->toContain('Caixa azul')
        ->and($data['actions'])->toBe([['label' => 'Ver os lembretes', 'url' => route('reminders.index')]])
        ->and($occurrence->refresh()->notified_at->toIso8601String())->toBe('2026-10-05T12:00:20+00:00')
        // Only the creator, though the reminder is shared.
        ->and($this->member->notifications()->count())->toBe(0);

    // The same slot again (a retried job) and the next minute notify nothing more.
    $reminder->runScheduledSlot(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:01', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($this->creator->notifications()->count())->toBe(1)
        ->and($reminder->occurrences()->count())->toBe(1);
});

test('completed occurrence is not notified', function () {
    $reminder = app(CreateReminder::class)($this->creator, ['title' => 'Pagar a luz', 'due_at' => '2026-10-05T09:00']);
    $occurrence = $reminder->occurrences()->sole();

    app(CompleteReminderOccurrence::class)($this->member, $occurrence);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($this->creator->notifications()->count())->toBe(0)
        ->and($occurrence->refresh()->notified_at)->toBeNull();

    // A deleted reminder is not notified either.
    $deleted = app(CreateReminder::class)($this->creator, ['title' => 'Apagado', 'due_at' => '2026-10-06T09:00']);
    $deleted->delete();
    $deleted->runScheduledSlot(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));

    expect($this->creator->notifications()->count())->toBe(0);
});

test('recurring reminder schedules next occurrence after slot', function () {
    $reminder = app(CreateReminder::class)($this->creator, [
        'title' => 'Regar as plantas',
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '09:00'],
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    $occurrences = $reminder->occurrences()->orderBy('due_at')->get();

    // The slot's occurrence was notified and stays open (overdue once past); the next one waits.
    expect($occurrences->pluck('due_at')->map->toIso8601String()->all())->toBe(['2026-10-05T12:00:00+00:00', '2026-10-06T12:00:00+00:00'])
        ->and($occurrences[0]->notified_at)->not->toBeNull()
        ->and($occurrences[1]->notified_at)->toBeNull()
        ->and($this->creator->notifications()->count())->toBe(1);

    // The next day's slot notifies its own occurrence and prepares the following.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($reminder->occurrences()->orderBy('due_at')->pluck('due_at')->map->toIso8601String()->all())
        ->toBe(['2026-10-05T12:00:00+00:00', '2026-10-06T12:00:00+00:00', '2026-10-07T12:00:00+00:00'])
        ->and($this->creator->notifications()->count())->toBe(2);
});

test('reminder due respects channel preferences', function () {
    UserFactory::joinWorkspace($this->creator, WorkspaceFactory::household(), [Module::REMINDERS, Module::WHATSAPP]);
    $account = WhatsappAccount::factory()->open()->for($this->creator)->create();
    $this->creator->forceFill(['whatsapp_notification_account_id' => $account->id, 'whatsapp_notification_phone' => '+5511955554444'])->save();

    $sentChannels = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sentChannels): void {
        $sentChannels[] = $event->channel;
    });

    $first = app(CreateReminder::class)($this->creator, ['title' => 'Primeiro', 'due_at' => '2026-10-05T09:00']);

    // By default: in the app and on WhatsApp, not by e-mail.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    $first->runScheduledSlot(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    expect($this->creator->notifications()->count())->toBe(1)
        ->and(WhatsappDelivery::query()->count())->toBe(1)
        ->and(WhatsappDelivery::query()->sole()->body)->toStartWith('*Lembrete: Primeiro*')
        ->and($sentChannels)->not->toContain('mail');

    // Switching WhatsApp off and e-mail on.
    $typeId = NotificationType::query()->where('slug', NotificationType::REMINDER_DUE)->value('id');

    foreach ([NotificationChannel::WHATSAPP => false, NotificationChannel::MAIL => true] as $channel => $isEnabled) {
        NotificationPreference::factory()->create([
            'user_id' => $this->creator->id,
            'notification_type_id' => $typeId,
            'notification_channel_id' => NotificationChannel::idFor($channel),
            'is_enabled' => $isEnabled,
        ]);
    }

    $sentChannels = [];
    $second = app(CreateReminder::class)($this->creator, ['title' => 'Segundo', 'due_at' => '2026-10-05T10:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 13:00', 'UTC'));
    app()->forgetScopedInstances();
    $second->runScheduledSlot(CarbonImmutable::parse('2026-10-05 13:00', 'UTC'));

    expect($this->creator->notifications()->count())->toBe(2)
        ->and(WhatsappDelivery::query()->count())->toBe(1)
        ->and($sentChannels)->toContain('mail');
});

test('the reminder goes to the creator when the assignee left its workspace, and to nobody when both left', function () {
    $reminder = Reminder::factory()->create([
        'created_by_id' => $this->creator->id,
        'assigned_to_id' => $this->member->id,
    ]);
    $household = WorkspaceFactory::household();

    WorkspaceMembership::query()->where('workspace_id', $household->id)->where('user_id', $this->member->id)->delete();

    expect($reminder->fresh()->recipient()?->is($this->creator))->toBeTrue();

    WorkspaceMembership::query()->where('workspace_id', $household->id)->where('user_id', $this->creator->id)->delete();

    expect($reminder->fresh()->recipient())->toBeNull();
});
