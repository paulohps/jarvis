---
name: jarvis-evolution-webhooks
description: Use when handling a new Evolution API webhook event or a new WhatsApp message type (or a side effect carried inside messages.upsert) end-to-end, from subscription and parsing through models, realtime, the WhatsApp screen, the inbox, the API and Pest tests.
---

# Evolution API webhook event or message type, end-to-end

Flow today:

```
Evolution instance ──POST /api/v1/webhooks/evolution (X-Evolution-Secret)──▶ VerifyEvolutionWebhookSecret
  ▶ EvolutionWebhookController            (always 200 {"status":"handled"|"ignored"} once authenticated)
  ▶ HandleEvolutionWebhook::__invoke()    (synchronous, in the request: match on the normalized event)
      ├─ messages.upsert / messages.set / send.message ─▶ RecordWhatsappChatMessages (rows via insertOrIgnore)
      ├─ other events ─▶ private method on HandleEvolutionWebhook
      ▼
  models (WhatsappTarget, WhatsappChatMessage, WhatsappChatReaction, WhatsappPollVote, WhatsappChatReceipt…)
      ├─ target saved ─▶ WhatsappTargetObserver ─▶ EnsureWhatsappConversation (inbox entry mirror)
      ├─ WhatsappChatUpdated / WhatsappChatPresenceChanged … (ShouldBroadcast, queue `whatsapp`, user's private channel)
      └─ NotificationCenter (WhatsappMessageReceived push, WhatsappAccountDisconnected…)
  ▶ Livewire: Whatsapp\Chat, Chats\ChatsPage (echo-private listeners) · API: WhatsappConversationMessageResource, Chat* via WhatsappChatProvider
```

There is **no job** between the webhook and the database. Handlers must be fast and idempotent, because the Evolution API may retry and `messages.set` can carry thousands of messages. Queue anything slow (e.g. `SyncWhatsappTargets::dispatch($account)` on `open`). Read `.ai/rules/whatsapp.md` before starting.

Commands (inside Sail):

```bash
sudo -n docker exec -u sail jarvis-dev-laravel.test-1 php artisan test --compact --filter=WhatsappChatMessagesWebhookTest
sudo -n docker exec -u sail jarvis-dev-laravel.test-1 vendor/bin/pint --dirty
sudo -n docker exec -u sail jarvis-dev-laravel.test-1 vendor/bin/phpstan analyse
```

## 1. Know the real payload first

Don't guess shapes. The envelope is `{event, instance, data, date_time, server_url, apikey}`, and `data` is Baileys' object for that event (one item, a list, or `{messages: [...]}` depending on event and version). Get a real one:
- from the Evolution API v2 sources for the version in `compose.yaml` (`evoapicloud/evolution-api:latest`; `EvolutionApiClient` was checked against v2.3), or
- by temporarily logging `$request->json()->all()` in a local branch while doing the action on a test phone. Strip phone numbers and names before turning it into a test payload, and never commit real JIDs.

Note the event name as Evolution sends it on the instance webhook (`MESSAGES_UPSERT` style or `messages.upsert`). `HandleEvolutionWebhook::normalizeEvent()` lowercases and turns `_` and `-` into `.`, so `GROUP_PARTICIPANTS_UPDATE` and `group-participants.update` both become `group.participants.update`.

## 2. Subscribe the instances

1. Add the UPPER_SNAKE name to `EvolutionApiClient::WEBHOOK_EVENTS` (`app/Services/Whatsapp/EvolutionApiClient.php`) and extend its docblock.
2. New instances get it from `createInstance()`. **Existing instances only get it when the webhook is set again**: `setWebhook()` runs in `SyncWhatsappTargets` (on `open` and "Sync contacts and groups"), `SyncWhatsappChats` ("Sync conversations") and `SyncWhatsappChat` (sync one chat). Say so in the change/README if users must sync.
3. If the payload needs data Evolution only keeps with a setting (e.g. `DATABASE_SAVE_DATA_*` in `compose.yaml`), add the env to the `evolution-api` service and to deploy docs.

## 3a. A new event

In `app/Actions/Whatsapp/HandleEvolutionWebhook.php`:
1. Add `public const string FOO_EVENT = 'foo.bar';` (normalized, dotted, lowercase).
2. Add a `match` arm in `__invoke()` → `$this->fooChanged($account, $data)`.
3. Write the private method. Accept one item or a list (`array_is_list($data) ? $data : [$data]`, filter with `is_array(...)`) and validate every field with `is_string`/`is_numeric` (payloads vary across versions). Find the chat with `$account->targets()->where('jid', $jid)->orWhere('lid', $jid)`. Return `true` only if something was applied, else `false`, so the response says `ignored`.
4. Write only on change (`forceFill(...)`; `if ($model->isDirty()) { save(); dispatch }`). Keep order-safe rules: an older event never undoes a newer one (compare timestamps, as `edit()` does with `edited_at`), and receipts only go up (`WhatsappChatMessage::raiseReceipt()`).
5. Update the class docblock list of events.

## 3b. A new message type, or something carried inside a message

In `app/Actions/Whatsapp/RecordWhatsappChatMessages.php`:
- **Content of its own** (shown as a bubble): `type()` keeps any type not in `IGNORED_TYPES`. Teach `text()` what stands for its text, store structured data as JSON in `payload()` (as location, contacts and poll are), and for a file add it to `WhatsappChatMessage::MEDIA_KINDS` so `mediaNode()`/`LoadWhatsappChatMedia` handle it. If it comes wrapped, add the wrapper to `unwrap()`. Give it an icon and label in `WhatsappChatMessage::summary()` (the list preview, the inbox's `last_message`, the AI context and the fallback text in `WhatsappChatProvider::toData()`).
- **No content, only an effect** (like reactions, edits, pins, votes, timers): add the type to `IGNORED_TYPES`, add a private `fooOf(array $message): ?array` extractor and a public `foo(WhatsappAccount $account, array $items): void` applier, call both in `__invoke()` after the rows are inserted (the target message may be in the same batch), and resolve the message with `messageOf($account, $externalId)`. Use `actorOf()` for who did it (`WhatsappChatReaction::ME` for the owner).
- Rows are inserted with `insertOrIgnore`, so new columns must be in every row array (`parse()['row']`), and no model events fire on messages.

## 4. Persist

- New column: migration on `whatsapp_chat_messages`/`whatsapp_targets` (+ cast, `@property`, `#[Fillable]` if set through Eloquent). A target column the inbox should show also goes in `WhatsappTargetObserver::MIRRORED` and `EnsureWhatsappConversation`.
- New table (one row per message × actor, like `whatsapp_poll_votes`, `whatsapp_chat_reactions`, `whatsapp_chat_receipts`): unique index on the natural key and `updateOrCreate`/`firstOrNew`, so retries are no-ops. Cascade on the message/target FK, because `RemoveWhatsappAccount` relies on cascades.
- Times from the payload: `millisecondsOr($ms, $seconds)` / `timestamp()`. Store UTC.

## 5. Realtime

- Something visible in a chat changed: `WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chat->friendly_id)`. Batch per chat (`tell($account, $chatIds)`), and not per message in a history import. Skip announcing inside `ApplyChatConversationsBulkAction::isRunning()`.
- Ephemeral state (like presence): a new `ShouldBroadcast` event in `app/Events/` on `new PrivateChannel(NotificationCreated::channelFor($userId))`, `broadcastAs()` dotted name, `broadcastQueue()` = `SyncWhatsappTargets::QUEUE`. Store nothing.
- Listen in `app/Livewire/Whatsapp/Chat.php` and, if the inbox needs it, `app/Livewire/Chats/ChatsPage.php` (`getListeners()`, `"{$channel},.whatsapp.foo"`). In the inbox, call `refreshList()` (island) instead of re-rendering everything.
- Push to phones only for live received messages (`notifyReceived()` honors `notifies_messages`). Don't push history.

## 6. Show it

- WhatsApp screen: the message partials under `resources/views/livewire/whatsapp/` and `resources/views/components/whatsapp/` (`message-payload`, `message-context`, `receipt`…). Use tokens only (see `.ai/rules/views-and-components.md`) and put strings in `lang/en.json` + `lang/pt_BR.json`.
- Inbox: `WhatsappChatProvider::toData()` maps rows to `ChatMessageData` (`kind` `text`|`file`, `text` falls back to `summary()['label']`). Render it in `resources/views/livewire/chats/partials/message.blade.php` if it needs more than text.
- API for the apps: `app/Http/Resources/WhatsappConversationMessageResource.php` (payload fields), documented in `docs/api/v1.yaml`. iOS (`apps/ios/JarvisKit`) and Android (`apps/android`) decode it, so update them or make sure the field is optional (see the `jarvis-api-endpoint` skill).

## 7. Tests

Pattern (`tests/Feature/WhatsappChatMessagesWebhookTest.php`):

```php
beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    config(['services.evolution.webhook_secret' => 'shared-secret']);
    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->postEvent = fn (string $event, array $data) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => $event, 'instance' => $this->account->instance_name, 'data' => $data],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
});
```

Cover:
- [ ] handled → `assertJson(['status' => 'handled'])`, and the rows/columns written
- [ ] the same payload posted twice changes nothing (retry idempotency)
- [ ] both spellings of the event (`FOO_BAR` and `foo.bar`) when Evolution varies
- [ ] one item and a list
- [ ] out-of-order (an older event after a newer one leaves the newer state)
- [ ] unknown instance, unknown chat or malformed data → `ignored`, nothing written
- [ ] broadcast: `Event::fake([WhatsappChatUpdated::class])` then `Event::assertDispatched(...)` with the chat's friendly id, and not dispatched when nothing changed
- [ ] `@lid` vs phone JID and group (`@g.us`, `participant`) variants when relevant
- [ ] inbox entry updated when a mirrored target column changed (`$target->conversation->fresh()`)
- [ ] screens and API render it (`Livewire::actingAs(...)->test(Chat::class, ...)`, `ApiWhatsappChatsTest`-style), and media fetched through `FakeEvolutionApi::install(['mediaOf' => ...])`
- [ ] no real phone numbers or names in fixtures: use fictional `55119…0000@s.whatsapp.net`-style JIDs like the existing tests

## Checklist

- [ ] Real payload confirmed (sources or local capture), anonymized
- [ ] `WEBHOOK_EVENTS` updated; how existing instances get it is documented
- [ ] Handler constant + `match` arm + defensive parsing + returns bool
- [ ] Message types: `IGNORED_TYPES`/`type()`/`text()`/`payload()`/`MEDIA_KINDS`/`summary()` as needed
- [ ] Idempotent writes (unique keys, `insertOrIgnore`/`updateOrCreate`), order-safe, receipts only up
- [ ] Mirrored columns added to `WhatsappTargetObserver::MIRRORED` + `EnsureWhatsappConversation`
- [ ] Broadcast only on change, batched per chat, queue `whatsapp`; listeners in `Whatsapp\Chat` / `ChatsPage`
- [ ] Views + translations; inbox mapping; API resource + `docs/api/v1.yaml`; apps tolerate the field
- [ ] Docblocks of `HandleEvolutionWebhook` / `RecordWhatsappChatMessages` updated
- [ ] Tests above green; pint; phpstan
