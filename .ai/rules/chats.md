---
paths:
  - 'app/Actions/Chats/**'
  - 'app/Services/Chats/**'
  - 'app/Models/Chat*.php'
  - 'app/Models/Concerns/HasAiTriage.php'
  - 'app/Events/Chat*.php'
  - 'app/Jobs/SendChatScheduledSlot.php'
  - 'app/Jobs/TriageChatConversations.php'
  - 'app/Console/Commands/SendDueChatScheduledMessages.php'
  - 'app/Http/Controllers/Chats/**'
  - 'app/Http/Controllers/Api/V1/Chats/**'
  - 'app/Http/Resources/Chat*.php'
  - 'app/Livewire/Chats/**'
  - 'resources/views/livewire/chats/**'
  - 'resources/views/components/chats/**'
  - 'app/Ai/Agents/Chat*.php'
  - 'tests/Feature/Chat*.php'
  - 'tests/Feature/ApiChatsTest.php'
---

# Chats (provider-agnostic inbox)

## Screens and API talk to a driver, never to a provider
Everything provider-specific goes through `App\Services\Chats\ChatProviderDriver`, found by slug in `ChatProviderRegistry` (`$registry->for($conversation)`). A driver answers with the DTOs of `app/Services/Chats/` (`ChatMessageData`, `ChatParticipantData`, `ChatFileContents`…), never with its own models. It throws `ChatProviderException` with a user-facing message, which Livewire shows as `$error` and the API turns into a 422.

## Capabilities gate every feature
`ChatCapabilities` (per conversation: `driver->capabilities($conversation)`) decides what the UI offers and what Actions accept: `send`, `files`, `threads`, `reactions`/`reactionEmojis`, `editMessages`, `deleteMessages`, `mentions`, `markRead`, `polls`, `formatting`… A read-only conversation uses `->readOnly()`. Actions re-check the flag (`SendChatMessage` prohibits `files`/`thread_id`, `SendChatPoll` refuses without `polls`). Features Jarvis adds itself (pin, priority, nickname, tags, contacts, reminders, schedules, AI draft) need no flag.

## ChatConversation is the list entry, conversable is the provider row
`chat_conversations.conversable` is the provider's own row (`whatsapp_target`, `slack_conversation` in the morph map). `name`, `picture_url`, `unread_count`, `last_message*` are copied there by the provider. A WhatsApp entry is a mirror of its `WhatsappTarget` (`WhatsappTargetObserver`). Writing a mirrored column straight on the entry is overwritten at the next target save.

## User state changes go through the provider first
Pin, archive, mute, unread mark, nickname and priority go through `ChangesChatConversation::change()`: `driver->entryChanged()` first (WhatsApp writes the target and tells the phone, and may throw), then the entry. Slack does nothing there. Never `forceFill()` these columns on the entry in a new Action. Example: `app/Actions/Chats/PinChatConversation.php`.

## Authorize with AuthorizesChats
Every Action starts with `authorizeConversation($actor, $conversation)`: `chats` module, the provider switched on for the user (`User::hasChatProvider()`: on for the workspace AND the member, in `chat_provider_workspace(_user)`, super admin always), and ownership. WhatsApp Actions underneath also require the `whatsapp` module. Look up conversations by `friendly_id` with `ofUser($actor)` (`FindsChatConversations`).

## Bulk actions do not announce each conversation
`ApplyChatConversationsBulkAction` (max 50) runs the single-conversation Actions and counts provider refusals as failed without stopping. While it runs, `ApplyChatConversationsBulkAction::isRunning()` is true and WhatsApp skips its per-chat broadcasts. The screen reloads once. New bulk operations must keep that flag.

## Scheduling: recurrence first, send_at is legacy
`ScheduleChatMessage` creates a `ChatScheduledMessage` with a recurrence (`SaveRecurrence`; without `recurrence` input it builds `once` at `send_at`). `recurrences:dispatch` calls `runScheduledSlot()` → `SendChatScheduledSlot` (queue `chats`, 1 try, 120s) → `SendChatScheduledMessage`. `chats:send-scheduled` only sends old rows with `send_at` and no recurrence (`due()` scope). Errors are re-keyed under `recurrence.*` / `send_at` / `poll.*`.

## A sending is recorded once per slot, never retried
`SendChatScheduledMessage` skips cancelled, already done (once) or already delivered slots (`deliveries()->where('slot_at', $slot)`). Every outcome becomes a `ChatScheduledDelivery` (sent/failed with the error). It sends as the owner through `SendChatMessage`/`SendChatPoll`, so a provider switched off since is refused and recorded as failed, not thrown.

## Scheduled body: text, AI prompt, poll or files, exclusive
`body` XOR `ai_prompt` (AI writes at each sending via `ChatReplyAgent::writeScheduled()`, needs `AiRoute::for('chat_reply')`, else `AiProviderNotConfigured`). `poll` cannot combine with `ai_prompt` or files, and its question is stored as `body`. Files are never taken from input: callers store them first with `ChatScheduledMessage::storeFile()` (private disk, `chats/scheduled/{user}`), and `forgetFiles()` deletes them when a once-message is done or cancelled.

## Forwards are scheduled copies
`ScheduleChatForward` (max 5 conversations, any provider) fetches the files now through the source driver and stores one copy per target conversation, then calls `ScheduleChatMessage` with `forwarded_from_id`. Providers are not told it is a forward. A failure in the transaction deletes the stored copies. API: `POST chats/conversations/{c}/messages/{m}/forward`.

## Realtime: two event names reach the inbox
Slack broadcasts `ChatConversationUpdated` (`.chat.conversation.updated`, queue `chats`). WhatsApp keeps broadcasting `WhatsappChatUpdated` (`.whatsapp.chat.updated`, queue `whatsapp`), which `ChatsPage::whatsappChatUpdated()` maps to the entry. Others: `.chat.account.synced`, `.chat.conversations.triaged`, `.whatsapp.chats.triaged`. All go on the user's private channel `NotificationCreated::channelFor($userId)`.

## The inbox list is an island
`ChatsPage` renders the conversation list in `@island(name: 'conversations')`. Call `refreshList()` (not `unset()` alone) when an action changes the list. `rendered()` then sends the island once. Rows act through `$wire`, and one shared menu serves every row. See `.ai/rules/livewire.md`. The partial is `resources/views/livewire/chats/partials/conversation-list.blade.php`.

## Field ids must not shadow form properties
A TallStackUI input takes its `wire:model` as `id`. A field named `tagName`, `action`, `submit`… hides the form's property and breaks Livewire's morph (the inbox's dialogs stopped opening). Give such fields their own `id`. `tests/Feature/ChatFormFieldNamesTest.php` scans the inbox and the WhatsApp chat.

## AI triage is separate from the user's priority
`RequestChatTriage` (max 20 unread) marks `ai_triage_requested_at` and queues `TriageChatConversations` (`ChatTriageAgent`, purpose `chat_reply`). Only `ai_*` columns are written (on the WhatsApp target too), never `priority`. Reading the conversation clears them (`HasAiTriage`). Sort `ai` uses `aiUrgencyFirst()`.

## Tags and contacts are per user and per layer
`ChatTag` (inbox) and `WhatsappTag` (WhatsApp screen) are separate tables. The migration copied the old WhatsApp tags once, but nothing syncs them afterwards. `ChatContact` links one person's conversations across providers. Both are the user's own, never shared.

## Provider rows are lookups
`chat_providers` rows (`whatsapp`, `slack`) come from `LookupSeeder`, match `ChatProvider::WHATSAPP`/`SLACK` and are found with `ChatProvider::idFor()`. A data migration that needs them must insert them itself, because seeders run after migrations (the WhatsApp backfill once left every chat without an entry).

## API surface is tested against docs
New routes under `routes/api.php` `chats.*` must be described in `docs/api/v1.yaml` (`ChatCapabilities` schema included), or `tests/Feature/ApiSurfaceTest.php` fails. Capabilities are exposed by `GET chats/providers` and on `GET chats/conversations/{id}` (`withChannel()`).

## Tests
Seed `LookupSeeder`, create the user `inWorkspace($workspace, [Module::CHATS, Module::WHATSAPP])`, sync `chatProviders()` on both the workspace and the membership, then `forgetMemberships()`. Fake both providers with `FakeEvolutionApi::install(); FakeSlackApi::alongside();`. A WhatsApp entry appears by saving a target with `last_message_at`. Slack uses `ChatConversation::factory()->forSlack($slackConversation)`. Template: `tests/Feature/ChatPollTest.php`.
