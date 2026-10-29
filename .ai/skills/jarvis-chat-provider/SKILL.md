---
name: jarvis-chat-provider
description: Use when adding a capability to the chats inbox (like polls or forwards) across WhatsApp and Slack, or when plugging a new messaging provider into the provider-agnostic chat layer, including capabilities, Actions, API, inbox UI, scheduling and tests.
---

# Chat layer: new capability or new provider

The inbox (`chats` module) never talks to WhatsApp or Slack directly. Screens, the API and jobs call `App\Actions\Chats\*`. Actions get the conversation's driver from `ChatProviderRegistry::for($conversation)` and talk only through `App\Services\Chats\ChatProviderDriver`. Drivers: `App\Services\Whatsapp\WhatsappChatProvider` and `App\Services\Slack\SlackChatProvider`. Read `.ai/rules/chats.md` first, then `.ai/rules/whatsapp.md` or `.ai/rules/slack.md` for the provider you touch.

Commands (inside Sail):

```bash
sudo -n docker exec -u sail jarvis-dev-laravel.test-1 php artisan test --compact --filter=ChatPollTest
sudo -n docker exec -u sail jarvis-dev-laravel.test-1 vendor/bin/pint --dirty
sudo -n docker exec -u sail jarvis-dev-laravel.test-1 vendor/bin/phpstan analyse
```

## The pieces

| Piece | File |
|---|---|
| Contract | `app/Services/Chats/ChatProviderDriver.php` |
| What a conversation supports | `app/Services/Chats/ChatCapabilities.php` (`toArray()` goes to the API) |
| DTOs in/out | `ChatMessageData`, `ChatFileData`, `ChatReactionData`, `ChatParticipantData`, `ChatAccountData`, `ChatFileContents`, `OutgoingChatMessage`, `OutgoingChatFile` |
| Driver lookup | `app/Services/Chats/ChatProviderRegistry.php` (`DRIVERS` by slug) |
| List entry | `app/Models/ChatConversation.php` (`conversable` = provider row) |
| Auth | `app/Actions/Chats/AuthorizesChats.php`, `ChangesChatConversation.php` |
| Inbox | `app/Livewire/Chats/ChatsPage.php`, `resources/views/livewire/chats/chats-page.blade.php`, `partials/message.blade.php`, `partials/conversation-list.blade.php` (island) |
| Scheduling | `ScheduleChatMessage`, `SendChatScheduledMessage`, `app/Jobs/SendChatScheduledSlot.php`, `app/Models/ChatScheduledMessage.php`, `ChatScheduledDelivery` |
| API | `app/Http/Controllers/Api/V1/Chats/*`, `app/Http/Resources/Chat*Resource.php`, `routes/api.php` (`chats.` group), `docs/api/v1.yaml` |
| Fakes | `tests/Fixtures/Whatsapp/FakeEvolutionApi.php`, `tests/Fixtures/Slack/FakeSlackApi.php` |

## A. Adding a capability (example: polls, forwards)

Polls went in as a flag plus a payload on the outgoing DTO. Forwards went in with no flag: they reuse `send`/`files` and are built on scheduling. Pick the shape first.

1. **Decide if it needs a flag.** It needs one when a provider may not support it, or may support it only on some conversations (archived, revoked token, group-only). Features Jarvis keeps itself (tags, reminders, nickname) need none.
2. **Add the flag** to `ChatCapabilities`: constructor param with a `false` default and a docblock line, `readOnly()` (decide if it survives read-only; sending things never does), and `toArray()` (snake_case key). Add the key to the `ChatCapabilities` schema in `docs/api/v1.yaml`.
3. **Set it in every driver's `capabilities()`.** Compute per conversation when needed: WhatsApp `mentions` is `$target->isGroup()`, and Slack says `reactionEmojis: SlackEmoji::characters()`. Leave it false where unsupported. Don't throw.
4. **Carry the data.** Prefer extending the existing DTOs over new driver methods. `OutgoingChatMessage::$poll` was added, and `isEmpty()` updated. Add a driver method to `ChatProviderDriver` only for a new verb (like `react`/`edit`/`delete`), and implement it in **both** drivers. The interface makes a missing one a fatal error, and phpstan catches it.
5. **Implement in the driver via the provider's own Actions.**
   - WhatsApp: call the `App\Actions\Whatsapp\*` Action (e.g. `SendWhatsappChatContent::poll(..., now: true)`), inside `$this->guard(...)`, which maps `ValidationException`/`AuthorizationException`/`EvolutionApiException` to `ChatProviderException`. Sends must go through `SendWhatsAppMessage` (delivery log), never `EvolutionApiClient` directly. Refuse read-only targets with `isWritable()`.
   - Slack: call `SlackApiClient` as the user, catch `SlackApiException`, record with `RecordSlackMessages`, and broadcast `ChatConversationUpdated`.
6. **Write one inbox Action** in `app/Actions/Chats/`: `final readonly`, `use AuthorizesChats`, `authorizeConversation()` first, then read `capabilities($conversation)` and refuse with `ValidationException::withMessages([...])` when the flag is off. Validate with `Validator::make()`. Reuse provider validation where it is the rule (`SendChatPoll` reuses `SendWhatsappChatContent::pollOf()`). Example: `app/Actions/Chats/SendChatPoll.php`.
7. **State the user sets on the entry** (pin/mute/archive-like) goes through `ChangesChatConversation::change()`, so `entryChanged()` can push it to the provider first. Handle the new key in `WhatsappChatProvider::entryChanged()`. Slack ignores it.
8. **Scheduling, if it can be scheduled.**
   - Add a nullable column on `chat_scheduled_messages` (migration; `json` + `'array'` cast, `#[Fillable]`), as `poll`, `files` and `forwarded_from_id` were.
   - Validate it in `ScheduleChatMessage` against the capability, and keep exclusivity rules (poll ⟂ `ai_prompt` ⟂ files). Re-key nested errors (`poll.*`).
   - Send it in `SendChatScheduledMessage` through the same inbox Action (as the owner, so it is re-authorized at send time). Record the outcome in `ChatScheduledDelivery`, and never throw out of the job.
   - Files: never accept them from input. Store with `ChatScheduledMessage::storeFile()`, send with `outgoingFiles()` (temp copies, unlinked in `finally`) and free with `forgetFiles()` when a once-message ends or is cancelled.
   - Show it in `ChatScheduledMessageResource` and on `resources/views/livewire/chats/scheduled-messages.blade.php`.
9. **API.** Add the route in the `chats.` group of `routes/api.php` (throttle like its siblings), a controller method that calls the Action and maps `ChatProviderException` to a 422 (`ValidationException::withMessages(['message' => ...])`), and the path and schema in `docs/api/v1.yaml`. `ApiSurfaceTest` fails if the route and the doc disagree. No app consumes `chats/*` yet (the apps use `whatsapp/*`), so no Swift/Kotlin change is required unless you add it there.
10. **Inbox UI.**
    - Gate the control on `$capabilities->yourFlag` in `chats-page.blade.php` (polls: `@if ($capabilities->polls)`).
    - Use a bool property + TallStackUI modal (`showPoll`, `showForward`). Give fields their own `id` (never `tagName`, `action`, `submit`…; `ChatFormFieldNamesTest`).
    - After changing what the list shows, call `refreshList()` (the list is an island).
    - Report refusals through `guard()` → `$error` and successes through `$notice`.
    - Add a `data-*` hook for tests (`data-chat-poll-open`).
    - Put every string in `lang/en.json` and `lang/pt_BR.json`.
11. **WhatsApp screen parity.** When the feature exists on WhatsApp, the WhatsApp screen (`app/Livewire/Whatsapp/Chat.php`) and `api/v1/whatsapp/*` usually have their own path through the same WhatsApp Action. Keep both working.

## B. Adding a provider

1. **Lookup row.** Add the slug constant to `app/Models/ChatProvider.php` and the row to `chat_providers` in `database/seeders/LookupSeeder.php` (`slug`, `name`, `sort_order`, `is_active`). If a migration backfills entries, it must insert the provider row itself, because seeders run after migrations.
2. **Account + conversation models** of the provider: `FooAccount` (owned by a user, secrets `encrypted` + `#[Hidden]`), `FooConversation` (the `conversable`), `FooMessage` with a unique key per conversation so replays upsert. Register the conversation's morph alias in `AppServiceProvider` (`Relation::enforceMorphMap`), and add factories plus a `ChatConversation::factory()->forFoo()` state like `forSlack()`.
3. **Driver.** `app/Services/Foo/FooChatProvider.php implements ChatProviderDriver`. Return DTOs only, ids are your messages' friendly ids, and throw `ChatProviderException` with translated user-facing messages. Implement every method. Return empty collections, null or a no-op where the provider has nothing (`thread()` on WhatsApp returns the message alone, `externalUrl()` is null, Slack's `entryChanged()` is a no-op). `styleSamples()` feeds the AI draft (the user's own recent texts elsewhere on the provider).
4. **Register** it in `ChatProviderRegistry::DRIVERS`. A provider in the lookup without a driver can be switched on but shows nothing.
5. **Keep entries current.** Create/update the `ChatConversation` (name, picture, kind `direct|group|channel`, `unread_count`, `last_message_at`, `last_message`) when messages arrive, like `EnsureSlackConversation` + `RecordSlackMessages`. Never overwrite the user's own columns (pin, priority, nickname, tags). Broadcast `ChatConversationUpdated($userId, $entry->friendly_id, $slug, $messageId)` (queue `chats`). Push received messages with `ChatMessageReceived` unless muted.
6. **Inbound events** (webhook): verify a signature or secret in middleware, dedupe, answer fast and queue the work on `chats` (see `SlackWebhookController`, `VerifySlackSignature`). Add the route next to `webhooks.slack` and document it.
7. **Connect flow.** Web route under the `chats.` group in `routes/web.php`, checked with `$user->hasChatProvider($slug)`. Connecting queues a sync job on `chats` (`ShouldBeUnique`, with a `#[Timeout]` below redis `retry_after`). Show the accounts through `accountsOf()` on `ChatChannelList`.
8. **Enablement.** Super admin switches it per workspace and member (`SetWorkspaceChatProviders`, `SetMemberChatProviders`, `app/Livewire/Admin/Workspaces/WorkspaceDetail.php`). Nothing to change there besides the lookup row.
9. **Icon and copy.** `resources/views/components/chats/provider-icon.blade.php`, plus translations.
10. **Env/config** in `config/services.php` and `.env.example`, and README setup (module features, env vars), as `.ai/guidelines` requires.
11. **Fake.** `tests/Fixtures/Foo/FakeFooApi.php` with `install()` (`Http::swap`, `preventStrayRequests`) and `alongside()` so it can join `FakeEvolutionApi`/`FakeSlackApi`.

## Tests to write

- Capability on/off per conversation, and a read-only conversation refusing (`tests/Feature/WhatsappChatProviderTest.php`, `SlackChatProviderTest.php`).
- Inbox Action through Livewire (`Livewire::actingAs($owner)->test(ChatsPage::class, ['conversationId' => ...])`), errors under the right keys, and nothing sent on refusal (inspect `Http::recorded()`).
- API happy path + 422 + 403 (module off, provider off for the member) in `tests/Feature/ApiChatsTest.php` style with `Sanctum::actingAs()`.
- Scheduling: once and recurring, one delivery per slot, failure recorded and not thrown, files cleaned up (`ChatScheduledMessagesTest.php`, `ChatScheduledForwardTest.php`, `ChatPollTest.php`).
- Setup boilerplate: copy the `beforeEach` of `tests/Feature/ChatPollTest.php`: `LookupSeeder`, `inWorkspace(..., [Module::CHATS, Module::WHATSAPP])`, `chatProviders()->sync()` on workspace and membership, `forgetMemberships()`, `FakeEvolutionApi::install(); FakeSlackApi::alongside();`.

## Checklist

- [ ] Flag added to `ChatCapabilities` (ctor, `readOnly()`, `toArray()`) and to `docs/api/v1.yaml`
- [ ] Both drivers answer the flag; unsupported = `false`, never an exception
- [ ] Driver uses the provider's Actions (WhatsApp → `SendWhatsAppMessage` path) inside `guard()`
- [ ] Inbox Action: `AuthorizesChats`, capability re-checked, `Validator::make()`, `ChatProviderException` bubbles
- [ ] User-state changes go through `ChangesChatConversation` / `entryChanged()`
- [ ] Scheduling: column + cast + fillable, validated in `ScheduleChatMessage`, sent in `SendChatScheduledMessage`, recorded per slot, files stored/forgotten
- [ ] API route in `chats.` group + throttle + docs; `ApiSurfaceTest` green
- [ ] UI gated on the capability, `refreshList()` after list changes, field ids safe, `data-*` hook, en + pt_BR strings
- [ ] WhatsApp screen / `whatsapp/*` API still work if they share the Action
- [ ] New provider: lookup row, morph alias, registry entry, factories, fake with `alongside()`, events on `chats` queue, README/env
- [ ] `php artisan test --compact --filter=Chat`, pint, phpstan
