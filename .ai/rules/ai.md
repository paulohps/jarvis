---
paths:
  - 'app/Ai/**'
  - 'app/Services/Ai/**'
  - 'app/Actions/Ai/**'
  - 'app/Models/Ai*.php'
  - 'app/Enums/AiModel.php'
  - 'app/Listeners/RecordAiUsage.php'
  - 'app/Exceptions/AiAnswerCutShort.php'
  - 'app/Exceptions/AiProviderNotConfigured.php'
  - 'app/Livewire/Admin/Ai/**'
  - 'resources/views/livewire/admin/ai/**'
  - 'app/Actions/Voice/**'
  - 'app/Services/Voice/**'
  - 'app/Http/Controllers/Api/V1/VoiceCommandController.php'
  - 'config/ai.php'
  - 'tests/Feature/Ai*.php'
  - 'tests/Feature/Voice*.php'
  - 'tests/Feature/*AiProvider*.php'
---

# AI (laravel/ai) and voice commands

## Keys live in the database, not in .env
Every agent builds its provider from an `AiProviderConnection` row: `provider()` returns `[Ai::build($this->route->providerConfig())]` (driver = `ai_providers.slug`, decrypted `api_key`, optional `base_url`, model). The `*_API_KEY` entries of `config/ai.php` are package defaults, and no agent reads them. Never add an agent that relies on the default provider. The key is `encrypted` and `#[Hidden]`, and only `api_key_last_four` is shown.

## Resolve the route per purpose with AiRoute
`AiRoute::for(AiPurpose::X)` returns the purpose's `AiPurposeAssignment` (connection + optional model, falling back to the connection's default when the model is no longer in `models`), else the single active connection, else null. Purposes: `voice_command`, `whatsapp_reply` (WhatsApp screen drafts, triage, titles, scheduled AI messages) and `chat_reply` (inbox drafts, triage, scheduled AI). A new purpose is a `LookupSeeder` row plus an `AiPurpose` constant.

## No AI configured is a typed, friendly error
Agents' `forUser()` throws `AiProviderNotConfigured` when `AiRoute::for()` is null. Actions that queue AI work check first (`ScheduleChatMessage`, `RequestChatTriage`, `SavesWhatsappMessage`) so the user gets an error now, not a failed job later. Screens show "No AI provider is set up. Ask the administrator."

## At most one active connection
A partial unique index allows one `is_active` row. Switch with `ActivateAiProviderConnection` (one transaction: off, then on), never by updating the flag directly. New connections start inactive.

## Removing is a soft delete with side effects
`DeleteAiProviderConnection` deletes the purpose assignments, sets `is_active = false` and `api_key = null` before `delete()` (to free the unique index slot), and keeps the row so `AiUsage::connection()` (`withTrashed()`) still resolves. `AiRoute` uses `whereHas('connection')`, so trashed connections are skipped. Removing the active one leaves no connection in use.

## Model prices are soft deleted too
`SaveAiModelPrice` with empty input/output prices soft-deletes the `AiModelPrice`. Saving the same provider+model again restores that row. `pricesUsageAt($at)` keeps pricing usage from before `deleted_at`, so past spend stays and later usage shows tokens only. Prices are USD per million tokens, and cache prices default to the input price.

## Every agent reports usage
Implement `App\Ai\Contracts\ReportsAiUsage` (`usageConnection()`, `usagePurpose()`, `usageUser()`). `App\Listeners\RecordAiUsage` (`handlePrompted`/`handleFailed`) writes one `AiUsage` row per call, failures included, and never breaks the call. An agent without the interface is invisible in the usage report (`app/Livewire/Admin/Ai/AiUsageReport.php`). Only `ConnectionCheck` reports a null purpose.

## Keep instructions cacheable
Agents use `#[CacheInstructions]`. Instructions and tools must stay the same from call to call, so put the current time (`"Now is …"` in the user's timezone) and anything per-request in the prompt, not in `instructions()`. See `VoiceCommandAgent` and `ChatReplyAgent::now()`.

## Token budget: reasoning models spend it thinking
Reply agents use `#[MaxTokens(8000)]` and `#[Timeout(60)]`. A 1,000 budget once cut drafts mid-sentence. If the last step finished with `FinishReason::Length`, throw `AiAnswerCutShort` and keep nothing (no truncated draft, no cut title). Jobs that call agents need a `#[Timeout]` above the agent's (e.g. `SendChatScheduledSlot` 120s).

## Fake agents in tests, never the HTTP
Use `SomeAgent::fake([...answers])->preventStrayPrompts()` or `fake(fn () => throw ...)`. Create an active `AiProviderConnection` (factory) so `AiRoute` resolves. Examples: `tests/Feature/WhatsappDraftAssistantTest.php`, `tests/Feature/VoiceCommandAgentTest.php`, `tests/Feature/AiUsageTest.php`, `tests/Feature/DeleteAiProviderConnectionTest.php`.

## Voice: interpreters only produce a VoiceAction
`POST /voice/commands` (remote, `VoiceCommandAgent`, purpose `voice_command`) and `POST /voice/actions` (the app's on-device AI) both end in a `VoiceAction`, which `ExecuteVoiceAction` runs. `VoiceActionTool` validates parameters with `VoiceAction::rules()` and only records the action, executing nothing. Actions: `shopping.add_item`, `shopping.check_item`, `reminders.create`, `reminders.complete`, `whatsapp.send`.

## ExecuteVoiceAction has no business rules
It resolves spoken names (`ResolveShoppingList`, `ResolveShoppingListItem`, `ResolveReminder`, `ResolveWhatsappTarget`) and calls the same Actions as web/API. A module that is off → `forbidden`, several candidates → `needs_clarification` (no action runs), none → `failed`. Messages are translated for TTS. Answers are always a `VoiceResult`.

## Fuzzy matching never guesses
`FuzzyMatcher` compares names without case, accents or filler words, and picks one only when its score is ≥ `MIN_SCORE` (0.6) and no other is within `MARGIN` (0.15). Otherwise it returns options. Resolvers only search the speaker's own data (their workspaces with the module, their own WhatsApp accounts).

## WhatsApp by voice always asks first
`whatsapp.send` always returns `needs_confirmation` with a token (`VoiceConfirmations`, cache, `TTL_MINUTES` 5, bound to the user, single use via `claim()`). `ConfirmVoiceAction` sends through `SendWhatsappNow` as a `voice` delivery, and the confirm route also requires the `whatsapp` module. Never send directly from a voice path.

## Adding a voice action touches the apps
A new action needs a `VoiceAction` constant + rules + `module()`, a branch in `ExecuteVoiceAction`, a resolver if it names records, docs in `docs/api/v1.yaml`, and the on-device interpreters in `apps/ios` (`JarvisKit/Sources/Networking/VoiceModels.swift`) and `apps/android`, which emit the same action names.

## Rate limits
`throttle:voice`, `throttle:whatsapp-reply` and `throttle:chat-reply` are 20/min per user (`AppServiceProvider::configureRateLimiting()`), because each call is paid. Any new endpoint that prompts an agent gets one of these limiters (or a new one).
