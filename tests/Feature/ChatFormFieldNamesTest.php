<?php

use App\Livewire\Chats\ChatsPage;
use App\Livewire\Whatsapp\Chat;
use App\Models\ChatProvider;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

/*
 * A field of a form whose `id` or `name` is a property of the form element
 * (`tagName`, `action`, `submit`…) hides that property: `form.tagName` is
 * then the field, and Livewire's morph, calling `tagName.toLowerCase()`,
 * stops halfway through every update — the screen no longer changes, as the
 * schedule dialog of the inbox that never opened (a TallStackUI input takes
 * its `wire:model` as its id).
 */

/**
 * The fields, by form, whose id or name hides a property of the form.
 *
 * @return list<string>
 */
function fieldsHidingFormProperties(string $html): array
{
    $reserved = [
        'tagName', 'nodeName', 'nodeType', 'localName', 'id', 'name', 'action', 'method', 'target', 'enctype', 'encoding',
        'elements', 'length', 'submit', 'reset', 'requestSubmit', 'checkValidity', 'reportValidity', 'noValidate',
        'acceptCharset', 'autocomplete', 'attributes', 'children', 'childNodes', 'parentNode', 'parentElement',
        'firstChild', 'lastChild', 'nextSibling', 'previousSibling', 'ownerDocument', 'className', 'classList', 'style',
        'dataset', 'title', 'lang', 'dir', 'hidden', 'textContent', 'innerHTML', 'outerHTML', 'isConnected',
        'getAttribute', 'setAttribute', 'hasAttribute', 'removeAttribute', 'querySelector', 'querySelectorAll',
        'closest', 'matches', 'contains', 'focus', 'blur', 'click', 'remove', 'append', 'appendChild', 'insertBefore',
        'replaceChild', 'removeChild', 'cloneNode', 'addEventListener', 'removeEventListener', 'dispatchEvent',
    ];

    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $hits = [];

    foreach ($xpath->query('//form//*[self::input or self::select or self::textarea or self::button or self::fieldset or self::output or self::object]') as $field) {
        /** @var DOMElement $field */
        foreach (['id', 'name'] as $attribute) {
            if (in_array($field->getAttribute($attribute), $reserved, true)) {
                $hits[] = "{$attribute}=\"{$field->getAttribute($attribute)}\"";
            }
        }
    }

    return $hits;
}

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-28 20:00', 'UTC'));
    FakeEvolutionApi::install();

    $this->workspace = Workspace::factory()->create();
    $this->owner = User::factory()->inWorkspace($this->workspace, [Module::CHATS, Module::WHATSAPP])->create(['timezone' => 'America/Campo_Grande']);
    $whatsapp = ChatProvider::idFor(ChatProvider::WHATSAPP);
    $this->workspace->chatProviders()->sync([$whatsapp => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->memberships()->sole()->chatProviders()->sync([$whatsapp => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->forgetMemberships();

    $account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net']);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'HI1', 'body' => 'Oi', 'sent_at' => now()->subMinute()]);
    $this->ana->forceFill(['last_message_at' => now()->subMinute()])->save();
});

test('no field of the inbox hides a property of its form, with the schedule dialog open', function () {
    $page = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->conversation->friendly_id])
        ->assertSeeHtml('data-chat-tag-editor')
        ->call('startSchedule')
        ->assertSet('showSchedule', true)
        ->assertSeeHtml('data-chat-schedule-form');

    expect(fieldsHidingFormProperties($page->html()))->toBe([]);
});

test('no field of the whatsapp chat hides a property of its form, with the schedule dialog open', function () {
    $chat = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->call('startSchedule')
        ->assertSet('showSchedule', true)
        ->assertSeeHtml('data-chat-schedule-form');

    expect(fieldsHidingFormProperties($chat->html()))->toBe([]);
});
