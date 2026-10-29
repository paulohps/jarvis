<?php

use App\Actions\Whatsapp\SetWhatsappChatTags;
use App\Livewire\Whatsapp\Chat;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTag;
use App\Models\WhatsappTarget;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'last_message_at' => now()]);
    $this->bruno = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bruno', 'last_message_at' => now()->subMinute()]);
});

test('the user creates tags, puts them on chats and filters the list by them', function () {
    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->call('editTags')
        ->assertSet('showTags', true)
        ->set('tagName', '  Clientes ')
        ->set('tagColor', 'orange')
        ->call('saveTag')
        ->assertHasNoErrors()
        ->set('tagName', 'clientes')
        ->call('saveTag')
        ->assertHasErrors('tagName')
        ->set('tagName', 'Família')
        ->set('tagColor', 'violet')
        ->call('saveTag')
        ->assertHasErrors('tagColor');

    $clients = WhatsappTag::query()->where('name', 'Clientes')->sole();
    expect($clients->only('user_id', 'color'))->toBe(['user_id' => $this->owner->id, 'color' => 'orange']);

    // A new tag comes picked for the chat being tagged.
    $screen->assertSet('chatTags', [$clients->friendly_id])
        ->call('saveChatTags')
        ->assertSet('showTags', false)
        ->assertSeeHtml('data-chat-tag="'.$clients->friendly_id.'"')
        ->assertSeeInIsland('data-chat-item-tag="'.$clients->friendly_id.'"')
        ->assertSeeInIsland('data-chat-item="'.$this->bruno->friendly_id.'"')
        ->call('filterByTag', $clients->friendly_id)
        ->assertSeeInIsland('data-chat-item="'.$this->ana->friendly_id.'"')
        ->assertDontSeeInIsland('data-chat-item="'.$this->bruno->friendly_id.'"')
        ->call('filterByTag', $clients->friendly_id)
        ->assertSeeInIsland('data-chat-item="'.$this->bruno->friendly_id.'"');

    expect($this->ana->fresh()->tags->pluck('name')->all())->toBe(['Clientes']);

    // Edited, then deleted: it comes off every chat.
    $screen->call('openSettings')
        ->call('editTag', $clients->friendly_id)
        ->set('tagName', 'Clientes VIP')
        ->call('saveTag')
        ->assertHasNoErrors()
        ->call('deleteTag', $clients->friendly_id);

    expect(WhatsappTag::query()->count())->toBe(0)
        ->and($this->ana->fresh()->tags)->toBeEmpty();
});

test('nobody else\'s tags go on a chat, and only the owner tags it', function () {
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();
    $foreign = WhatsappTag::factory()->for($stranger)->create();
    $mine = WhatsappTag::factory()->for($this->owner)->create();

    app(SetWhatsappChatTags::class)($this->owner, $this->ana, [$foreign->friendly_id, $mine->friendly_id]);
    expect($this->ana->fresh()->tags->pluck('id')->all())->toBe([$mine->id]);

    expect(fn () => app(SetWhatsappChatTags::class)($stranger, $this->ana, [$foreign->friendly_id]))->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->call('deleteTag', $foreign->friendly_id)
        ->assertNotFound();
});

test('no tag name field is rendered with the id "tagName", which hides the form\'s tagName from Livewire and breaks every action of the screen', function () {
    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->assertDontSeeHtml('id="tagName"')
        ->assertSeeHtml('id="chat-tag-name"')
        ->assertSeeHtml('id="manage-tag-name"');
});
