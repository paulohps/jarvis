<?php

use App\Actions\Reminders\CreateReminder;
use App\Actions\Shopping\CreateShoppingList;
use App\Actions\Voice\ExecuteVoiceAction;
use App\Actions\Voice\ResolveReminder;
use App\Actions\Voice\ResolveShoppingList;
use App\Actions\Voice\ResolveWhatsappTarget;
use App\Models\Module;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\ShoppingList;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Services\Voice\VoiceAction;
use App\Services\Voice\VoiceResult;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->user = User::factory()
        ->withModules([Module::SHOPPING, Module::REMINDERS, Module::WHATSAPP])
        ->create(['timezone' => 'America/Sao_Paulo']);
});

test('ambiguous list name asks for clarification', function () {
    $week = ShoppingList::factory()->create(['name' => 'Compras da semana']);
    $month = ShoppingList::factory()->create(['name' => 'Compras do mês']);
    ShoppingList::factory()->create(['name' => 'Farmácia']);
    ShoppingList::factory()->archived()->create(['name' => 'Compras antigas']);

    $resolution = app(ResolveShoppingList::class)($this->user, 'compras');

    // Never picks one of two close candidates; archived lists are not candidates.
    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->options)->toBe([
            ['id' => $week->friendly_id, 'label' => 'Compras da semana'],
            ['id' => $month->friendly_id, 'label' => 'Compras do mês'],
        ]);

    // Through the executor: nothing added, the options to ask about.
    $result = app(ExecuteVoiceAction::class)($this->user, VoiceAction::from(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'leite', 'list' => 'lista de compras']));

    expect($result->status)->toBe(VoiceResult::NEEDS_CLARIFICATION)
        ->and($result->clarification['parameter'])->toBe('list')
        ->and(array_column($result->clarification['options'], 'id'))->toBe([$week->friendly_id, $month->friendly_id])
        ->and($result->message)->toBe('Which list? Compras da semana or Compras do mês?')
        ->and($week->items()->count() + $month->items()->count())->toBe(0);

    // The option picked goes back as the parameter and resolves directly.
    $answer = app(ExecuteVoiceAction::class)($this->user, VoiceAction::from(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'leite', 'list' => $month->friendly_id]));

    expect($answer->status)->toBe(VoiceResult::DONE)
        ->and($month->items()->sole()->name)->toBe('leite');

    // Two reminders alike: asks too.
    app(CreateReminder::class)($this->user, ['title' => 'Pagar a escola']);
    app(CreateReminder::class)($this->user, ['title' => 'Pagar a escola de inglês']);

    $reminder = app(ExecuteVoiceAction::class)($this->user, VoiceAction::from(VoiceAction::REMINDERS_COMPLETE, ['reminder' => 'pagar escola']));

    expect($reminder->status)->toBe(VoiceResult::NEEDS_CLARIFICATION)
        ->and($reminder->clarification['parameter'])->toBe('reminder')
        ->and($reminder->clarification['options'])->toHaveCount(2)
        ->and(ReminderOccurrence::query()->whereNotNull('completed_at')->count())->toBe(0);

    // No candidate: a friendly error, nothing run.
    $none = app(ExecuteVoiceAction::class)($this->user, VoiceAction::from(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'leite', 'list' => 'churrasco']));

    expect($none->status)->toBe(VoiceResult::FAILED)
        ->and($none->message)->toBe('I could not find a shopping list called "churrasco".');
});

test('target resolution only considers the users own accounts', function () {
    $mine = WhatsappAccount::factory()->open()->for($this->user)->create(['nickname' => 'Pessoal']);
    $ana = WhatsappTarget::factory()->create(['whatsapp_account_id' => $mine->id, 'name' => 'Ana Souza']);
    WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $mine->id, 'name' => 'Família']);

    $someoneElse = User::factory()->withModules([Module::WHATSAPP])->create();
    $theirs = WhatsappAccount::factory()->open()->for($someoneElse)->create(['nickname' => 'Pessoal']);
    $theirAna = WhatsappTarget::factory()->create(['whatsapp_account_id' => $theirs->id, 'name' => 'Ana']);
    $theirBoss = WhatsappTarget::factory()->create(['whatsapp_account_id' => $theirs->id, 'name' => 'Chefe']);

    // Their exact "Ana" is not a candidate: only mine is.
    $resolution = app(ResolveWhatsappTarget::class)($this->user, 'Ana');

    expect($resolution->match?->is($ana))->toBeTrue();

    // Not even by its friendly id, nor by a name only they have.
    expect(app(ResolveWhatsappTarget::class)($this->user, $theirAna->friendly_id)->isResolved())->toBeFalse()
        ->and(app(ResolveWhatsappTarget::class)($this->user, 'Chefe')->isResolved())->toBeFalse();

    $result = app(ExecuteVoiceAction::class)($this->user, VoiceAction::from(VoiceAction::WHATSAPP_SEND, ['target' => 'chefe', 'body' => 'Oi']));

    expect($result->status)->toBe(VoiceResult::FAILED)
        ->and($result->message)->toBe('I could not find a contact or group called "chefe" in your WhatsApp accounts.')
        ->and($result->confirmation)->toBeNull();

    // Two of my own accounts with an "Ana": asks which, naming the account.
    $work = WhatsappAccount::factory()->open()->for($this->user)->create(['nickname' => 'Trabalho']);
    $workAna = WhatsappTarget::factory()->create(['whatsapp_account_id' => $work->id, 'name' => 'Ana Souza']);

    $ambiguous = app(ResolveWhatsappTarget::class)($this->user, 'Ana Souza');

    expect($ambiguous->options)->toEqualCanonicalizing([
        ['id' => $ana->friendly_id, 'label' => 'Ana Souza (Pessoal)'],
        ['id' => $workAna->friendly_id, 'label' => 'Ana Souza (Trabalho)'],
    ]);

    // Saying the account settles it.
    expect(app(ResolveWhatsappTarget::class)($this->user, 'Ana Souza', 'trabalho')->match?->is($workAna))->toBeTrue();

    expect($theirBoss->exists)->toBeTrue();
});

test('unnamed list goes to the default list', function () {
    ShoppingList::factory()->create(['name' => 'Farmácia']);
    $market = ShoppingList::factory()->asDefault()->create(['name' => 'Mercado']);

    expect(app(ResolveShoppingList::class)($this->user, null)->match?->is($market))->toBeTrue();

    $result = app(ExecuteVoiceAction::class)($this->user, VoiceAction::from(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'leite']));

    expect($result->status)->toBe(VoiceResult::DONE)
        ->and($market->items()->pluck('name')->all())->toBe(['leite']);

    // A named list still wins over the default.
    expect(app(ResolveShoppingList::class)($this->user, 'farmácia')->match?->name)->toBe('Farmácia');

    // With no default, two lists are still a question.
    $market->update(['is_default' => false]);

    expect(app(ResolveShoppingList::class)($this->user, null)->isAmbiguous())->toBeTrue();
});

test('single match resolves directly', function () {
    $week = app(CreateShoppingList::class)($this->user, ['name' => 'Compras da semana']);
    app(CreateShoppingList::class)($this->user, ['name' => 'Compras do mês']);

    // "dessa semana" → the closest name, alone.
    expect(app(ResolveShoppingList::class)($this->user, 'dessa semana')->match?->is($week))->toBeTrue()
        // A typo still finds it.
        ->and(app(ResolveShoppingList::class)($this->user, 'semanna')->match?->is($week))->toBeTrue();

    $result = app(ExecuteVoiceAction::class)($this->user, VoiceAction::from(VoiceAction::SHOPPING_ADD_ITEM, ['item' => 'leite', 'list' => 'dessa semana']));

    expect($result->status)->toBe(VoiceResult::DONE)
        ->and($result->message)->toBe('Added "leite" to "Compras da semana".');

    // A single active list needs no name at all.
    ShoppingList::query()->where('name', 'Compras do mês')->delete();

    expect(app(ResolveShoppingList::class)($this->user, null)->match?->is($week))->toBeTrue();

    // A reminder by part of its title.
    $reminder = app(CreateReminder::class)($this->user, ['title' => 'Levar o carro na revisão']);
    app(CreateReminder::class)($this->user, ['title' => 'Comprar presente']);

    $occurrence = app(ResolveReminder::class)($this->user, 'revisão do carro')->match;

    expect($occurrence)->toBeInstanceOf(ReminderOccurrence::class)
        ->and($occurrence->reminder_id)->toBe($reminder->id);

    // Completed reminders are no longer candidates.
    $occurrence->update(['completed_at' => now()]);

    expect(app(ResolveReminder::class)($this->user, 'revisão do carro')->isResolved())->toBeFalse()
        ->and(Reminder::query()->count())->toBe(2);

    // One contact by its first name.
    $account = WhatsappAccount::factory()->open()->for($this->user)->create();
    $family = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $account->id, 'name' => 'Família Silva']);
    WhatsappTarget::factory()->create(['whatsapp_account_id' => $account->id, 'name' => 'Pedro']);

    expect(app(ResolveWhatsappTarget::class)($this->user, 'grupo da família')->match?->is($family))->toBeTrue();
});
