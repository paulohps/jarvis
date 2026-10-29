<?php

use App\Actions\Reminders\CompleteReminderOccurrence;
use App\Actions\Reminders\CreateReminder;
use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\ToggleShoppingListItem;
use App\Actions\Voice\ExecuteVoiceAction;
use App\Events\ShoppingListChanged;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappTarget;
use App\Services\Voice\VoiceAction;
use App\Services\Voice\VoiceResult;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()
        ->withModules([Module::SHOPPING, Module::REMINDERS, Module::WHATSAPP])
        ->create(['timezone' => 'America/Sao_Paulo']);

    $this->execute = fn (string $name, array $parameters, ?User $actor = null): VoiceResult => app(ExecuteVoiceAction::class)(
        $actor ?? $this->user,
        VoiceAction::from($name, $parameters),
    );
});

test('voice action reuses the domain actions', function () {
    // The executor calls the Actions of the shopping and reminders modules.
    $dependencies = array_map(
        fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        (new ReflectionMethod(ExecuteVoiceAction::class, '__construct'))->getParameters(),
    );

    expect($dependencies)->toContain(
        AddShoppingListItem::class,
        ToggleShoppingListItem::class,
        CreateReminder::class,
        CompleteReminderOccurrence::class,
    );

    // …and writes nothing itself.
    $source = (string) file_get_contents((new ReflectionClass(ExecuteVoiceAction::class))->getFileName());

    expect($source)->not->toMatch('/->(create|save|update|delete|forceFill|fill)\(/');

    Event::fake([ShoppingListChanged::class]);

    // Adding an item: positioned and broadcast by AddShoppingListItem.
    $list = ShoppingList::factory()->create(['name' => 'Compras da semana']);
    ShoppingListItem::factory()->for($list, 'list')->create(['name' => 'Pão', 'position' => 4]);

    $result = ($this->execute)(VoiceAction::SHOPPING_ADD_ITEM, ['item' => ' leite ', 'quantity' => '2 L', 'list' => 'dessa semana']);

    $item = $list->items()->where('name', 'leite')->sole();

    expect($result->status)->toBe(VoiceResult::DONE)
        ->and($result->message)->toBe('Added "leite" to "Compras da semana".')
        ->and($item->position)->toBe(5)
        ->and($item->quantity_note)->toBe('2 L')
        ->and($item->created_by_id)->toBe($this->user->id);

    Event::assertDispatched(ShoppingListChanged::class, fn (ShoppingListChanged $event) => $event->change === ShoppingListChanged::ITEM_ADDED);

    // The Action's own validation answers, with its message.
    $archived = ShoppingList::factory()->archived()->create(['name' => 'Festa']);

    $refused = ($this->execute)(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'bolo', 'list' => $archived->friendly_id]);

    expect($refused->status)->toBe(VoiceResult::FAILED)
        ->and(ShoppingListItem::query()->where('name', 'bolo')->exists())->toBeFalse();

    // Checking an item: recorded by ToggleShoppingListItem.
    $checked = ($this->execute)(VoiceAction::SHOPPING_CHECK_ITEM, ['item' => 'pao']);

    expect($checked->status)->toBe(VoiceResult::DONE)
        ->and($checked->message)->toBe('Checked "Pão" on "Compras da semana".')
        ->and($list->items()->where('name', 'Pão')->sole()->checked_by_id)->toBe($this->user->id);

    // Creating a reminder: the schedule is CreateReminder's, in the user's timezone.
    $created = ($this->execute)(VoiceAction::REMINDERS_CREATE, ['title' => 'Pagar a escola', 'due_at' => '2026-10-06T09:00']);
    $reminder = Reminder::query()->where('title', 'Pagar a escola')->sole();

    expect($created->status)->toBe(VoiceResult::DONE)
        ->and($reminder->created_by_id)->toBe($this->user->id)
        ->and($reminder->recurrence->frequency->slug)->toBe(RecurrenceFrequency::ONCE)
        ->and($reminder->occurrences()->sole()->due_at->toIso8601ZuluString())->toBe('2026-10-06T12:00:00Z');

    // A past date is refused by the Action, not by the voice.
    $past = ($this->execute)(VoiceAction::REMINDERS_CREATE, ['title' => 'Ontem', 'due_at' => '2026-10-01T09:00']);

    expect($past->status)->toBe(VoiceResult::FAILED)
        ->and(Reminder::query()->where('title', 'Ontem')->exists())->toBeFalse();

    // Completing a repeating reminder: CompleteReminderOccurrence creates the next one.
    $daily = app(CreateReminder::class)($this->user, [
        'title' => 'Tomar remédio',
        'recurrence' => ['frequency' => RecurrenceFrequency::DAILY, 'time_of_day' => '08:00'],
    ]);

    $completed = ($this->execute)(VoiceAction::REMINDERS_COMPLETE, ['reminder' => 'remédio']);

    expect($completed->status)->toBe(VoiceResult::DONE)
        ->and($completed->message)->toBe('Reminder "Tomar remédio" completed.')
        ->and($daily->occurrences()->whereNotNull('completed_at')->sole()->completed_by_id)->toBe($this->user->id)
        ->and($daily->occurrences()->whereNull('completed_at')->count())->toBe(1);
});

test('voice action for a disabled module is forbidden', function () {
    $shopper = User::factory()->withModules([Module::SHOPPING])->create(['timezone' => 'UTC']);
    $list = ShoppingList::factory()->create(['name' => 'Mercado']);
    $account = WhatsappAccount::factory()->open()->for($shopper)->create();
    WhatsappTarget::factory()->create(['whatsapp_account_id' => $account->id, 'name' => 'Ana']);

    $reminder = ($this->execute)(VoiceAction::REMINDERS_CREATE, ['title' => 'Pagar a escola'], $shopper);
    $whatsapp = ($this->execute)(VoiceAction::WHATSAPP_SEND, ['target' => 'Ana', 'body' => 'Oi'], $shopper);

    expect($reminder->status)->toBe(VoiceResult::FORBIDDEN)
        ->and($reminder->message)->toBe('The Agenda module is switched off for you.')
        ->and($reminder->toArray()['action'])->toBe(VoiceAction::REMINDERS_CREATE)
        ->and($whatsapp->status)->toBe(VoiceResult::FORBIDDEN)
        ->and($whatsapp->confirmation)->toBeNull()
        ->and(Reminder::query()->count())->toBe(0)
        ->and(WhatsappDelivery::query()->count())->toBe(0);

    // In the user's language.
    app()->setLocale('pt_BR');

    expect(($this->execute)(VoiceAction::REMINDERS_COMPLETE, ['reminder' => 'escola'], $shopper)->message)
        ->toBe('O módulo Agenda está desligado para você.');

    // The module they have still works.
    expect(($this->execute)(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'Leite'], $shopper)->status)->toBe(VoiceResult::DONE)
        ->and($list->items()->sole()->name)->toBe('Leite');
});

test('voice actions are validated before they run', function () {
    expect(fn () => VoiceAction::from('shopping.delete_everything', []))
        ->toThrow(ValidationException::class);

    try {
        VoiceAction::from(VoiceAction::WHATSAPP_SEND, ['target' => ' ', 'body' => 'Oi', 'extra' => 'x']);
        $this->fail('The blank target was accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['parameters.target']);
    }

    $action = VoiceAction::from(VoiceAction::SHOPPING_ADD_ITEM, ['item' => ' Leite ', 'list' => '', 'extra' => 'x']);

    expect($action->parameters)->toBe(['item' => 'Leite', 'quantity' => null, 'list' => null])
        ->and($action->module())->toBe(Module::SHOPPING);
});
