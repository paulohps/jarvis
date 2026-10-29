---
paths:
  - 'app/Actions/Whatsapp/**'
  - 'app/Services/Whatsapp/**'
  - 'app/Models/Whatsapp*.php'
  - 'app/Jobs/*Whatsapp*.php'
  - 'app/Events/Whatsapp*.php'
  - 'app/Observers/Whatsapp*.php'
  - 'app/Exceptions/EvolutionApiException.php'
  - 'app/Http/Controllers/Webhooks/EvolutionWebhookController.php'
  - 'app/Http/Middleware/VerifyEvolutionWebhookSecret.php'
  - 'app/Http/Controllers/Whatsapp/**'
  - 'app/Http/Controllers/Api/V1/Whatsapp/**'
  - 'app/Http/Resources/Whatsapp*.php'
  - 'app/Livewire/Whatsapp/**'
  - 'resources/views/livewire/whatsapp/**'
  - 'resources/views/components/whatsapp/**'
  - 'app/Console/Commands/PruneWhatsappChatMedia.php'
  - 'tests/Fixtures/Whatsapp/**'
  - 'tests/Feature/*Whatsapp*.php'
  - 'tests/Feature/Evolution*.php'
---

# WhatsApp (Evolution API)

## One Evolution instance per connected number
`WhatsappAccount` is one Baileys instance on the self-hosted Evolution API v2 (`instance_name`), owned by one user and never shared. `ConnectWhatsappAccount` creates the instance first and stores the account only once it exists. The status (`connecting`/`open`/`closed`) comes only from the `connection.update` webhook. Removing (`RemoveWhatsappAccount`) pauses its schedules, logs out and deletes the instance, then cascades targets, messages and deliveries.

## All HTTP goes through EvolutionApiClient
`app/Services/Whatsapp/EvolutionApiClient.php` is the only class that calls the Evolution API (global `apikey` header, `services.evolution.timeout` 10s, `sync_timeout` 50s for contacts/groups). Any status ≥ 400 or no answer throws `EvolutionApiException`. Add a method there, plus its endpoint in `FakeEvolutionApi::ENDPOINTS` and a default answer, or tests fail on stray requests.

## Sending: only SendWhatsAppMessage
`app/Actions/Whatsapp/SendWhatsAppMessage.php` is the single way out (scheduled, send-now, notifications, voice, chat screen, inbox). It logs a `whatsapp_deliveries` row and queues `DeliverWhatsappMessage` (queue `whatsapp`, 3 tries). Never call `sendText()`/`sendMedia()`/`sendPoll()` from elsewhere. `now: true` (the chat layer) runs the job with `dispatchSync` and refreshes the delivery. A closed account fails at once without calling the API.

## Scheduled slots are idempotent
A scheduled run is unique per message, target and `scheduled_for` (DB unique index plus `existingSlot()`; a `UniqueConstraintViolationException` returns the row another worker logged). A slot already logged never asks the AI again (`WriteScheduledWhatsappMessage`). Keep this when you add a sending path.

## Targets keep the chat state; the inbox entry is a copy
`WhatsappTarget` is the source of truth of a chat (pin, archive, mute, unread, nickname, priority, `ai_*`). `WhatsappTargetObserver` rewrites its `ChatConversation` through `EnsureWhatsappConversation` whenever a `MIRRORED` column changes. A column the inbox should show must be added to `MIRRORED` and to `EnsureWhatsappConversation`. Only targets with `hasChat()` (a message or a pin) get an entry.

## Messages are inserted raw: no model events
`RecordWhatsappChatMessages` writes `whatsapp_chat_messages` with `insertOrIgnore` in chunks (unique `whatsapp_target_id` + `external_id`), so retried webhooks and replayed history add nothing and no Eloquent event fires on a message. Side effects (unread count, last message, broadcast, push) happen in `touch()`/`notifyReceived()`, and the entry is refreshed by saving the target there.

## JIDs: prefer the phone JID, keep the @lid
A contact chat is keyed by its `@s.whatsapp.net` JID when WhatsApp gives one (`remoteJidAlt`, `senderPn`), else the `@lid` JID; the `@lid` is kept in `lid` because presence updates and timers may use it. Look up targets with `where('jid', $jid)->orWhere('lid', $jid)`. Groups are `@g.us`; broadcasts, newsletters and status updates are dropped.

## Receipts only go up
Ticks are ints `RECEIPT_FAILED..RECEIPT_PLAYED` on `WhatsappChatMessage`. Use `WhatsappChatMessage::raiseReceipt()`, never a plain update, so an old event cannot lower a tick. Per-recipient times (`WhatsappChatReceipt`) exist only for one-to-one chats: the Evolution API does not pass on group member receipts.

## Media is fetched lazily and pruned
Webhooks carry no file (`base64: false`). `LoadWhatsappChatMedia` fetches the file via `getBase64FromMediaMessage` on first view/download, stores it on the private disk (`WhatsappChatMedia::disk()`, R2 in production) and audio comes as MP4/AAC for Safari. Files over `WhatsappChatMessage::MAX_MEDIA_BYTES` (32 MB) stay on the phone. `whatsapp:prune-media` (daily 04:00) deletes files not opened for `WHATSAPP_MEDIA_KEEP_DAYS`, except files a pending/failed delivery or a schedule still needs.

## Media URLs for the apps are signed
`api.whatsapp.media` and `api.chats.file` take no token: a temporary signed URL (put in the resource) stands for the owner. Never return a storage path or an unsigned media URL. The web route `whatsapp.chat.media` uses the session.

## Unread counts are Jarvis' own
`unread_count` is counted by Jarvis (live received messages add, a message the number sent from any device resets). A chat read on the phone lowers it only when the account `syncs_phone_reads`. Read receipts go to WhatsApp only when `sends_read_receipts` is on (off by default), per account and per chat (`SetWhatsappChatPrivacy`). Reading a chat clears its AI triage (`HasAiTriage`).

## Two access gates for the same chat
WhatsApp Actions check the `whatsapp` module (`AuthorizesWhatsappModule`, `AuthorizesWhatsappChat` plus the `use` policy on the account). The inbox (`chats` module) reaches WhatsApp through these same Actions, so a user needs both modules. Writes that reach WhatsApp use `authorizeConnectedChat()`, which refuses a closed number under the given field.

## Presence needs a subscription
`presence.update` only arrives for chats the number subscribed to. `WatchWhatsappChatPresence` subscribes by sending `paused`, at most once per `WATCH_SECONDS` per chat. Presence is broadcast (`WhatsappChatPresenceChanged`) and never stored.

## Proxy per number
`SetWhatsappAccountProxy` sets the proxy on the Evolution API first, which tests it. A refusal comes back under `host` and nothing is saved. `proxy_password` is encrypted and hidden, and a blank password keeps the saved one. A recreated instance gets the proxy back.

## Two scheduled-message systems
`WhatsappMessage` (screen `whatsapp.messages.*`, API `whatsapp/messages`) sends to many targets with a `{% %}` template body (`WhatsappTemplateSchema`), an `ai_prompt` or a `poll`. The inbox's per-conversation schedule is `ChatScheduledMessage` (see `.ai/rules/chats.md`). Both run on `recurrences:dispatch`. A poll has no template, file or AI prompt (`SendWhatsappChatContent::pollOf()`: 2 to 10 options).

## Queues and timeouts
WhatsApp jobs and broadcasts use the `whatsapp` queue (`SyncWhatsappTargets::QUEUE`, `DeliverWhatsappMessage::QUEUE`, Horizon `supervisor-whatsapp`, timeout 60). `SyncWhatsappTargets` gets 120s, under redis `retry_after` 150. Keep any new job's `#[Timeout]` below `retry_after`, or it runs twice.

## AI on the WhatsApp screen uses `whatsapp_reply`
`WhatsappReplyAgent`, `WhatsappTriageAgent` and `WhatsappDraftTitleAgent` resolve `AiRoute::for(AiPurpose::WHATSAPP_REPLY)`. The inbox uses `chat_reply` instead. Answers cut short by the token limit throw `AiAnswerCutShort` and are never kept (see `.ai/rules/ai.md`).

## Tests: inline payloads plus FakeEvolutionApi
Post webhooks with `postJson(route('webhooks.evolution'), ['event' => ..., 'instance' => $account->instance_name, 'data' => ...], [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'])` after `config(['services.evolution.webhook_secret' => 'shared-secret'])`. Fake outgoing calls with `FakeEvolutionApi::install([...overrides by client method])`. Factories: `WhatsappAccount::factory()->open()|connecting()|closed()`, `WhatsappTarget::factory()->contact()|group()`. Examples: `tests/Feature/WhatsappChatMessagesWebhookTest.php`, `tests/Feature/EvolutionWebhookTest.php`.
