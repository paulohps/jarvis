---
paths:
  - 'app/Events/**'
  - 'routes/channels.php'
  - 'config/broadcasting.php'
  - 'config/reverb.php'
  - 'resources/js/echo.js'
  - 'resources/js/components/whatsapp-presence.js'
  - 'apps/ios/JarvisKit/Sources/Realtime/**'
  - 'apps/ios/Jarvis/App/RealtimeCoordinator.swift'
  - 'apps/android/app/src/main/java/app/jarvis/android/realtime/**'
---

# Realtime (Reverb)

## Only two private channels exist
`routes/channels.php` defines `App.Models.User.{id}`, which only the user may join, and `shopping-lists.{listId}`, which takes the list's `friendly_id` and is authorized by `ShoppingList::visibleTo($user)`. Every per-user event (notifications, WhatsApp, chats) goes on the user channel. Add a new channel only when several users share the record. Build names with `NotificationCreated::channelFor($userId)` / `ShoppingListChanged::channelFor($friendlyId)`; never hand-write the strings.

## The user channel carries the numeric id
`App.Models.User.{id}` is the one place the internal id reaches clients, through `UserResource::broadcast_channel` (`private-App.Models.User.{id}`). The apps subscribe to that value from `/me`; they never build it themselves. Keep that field if you rename the channel.

## Two auth endpoints, same rules
The web signs channels at `/broadcasting/auth`, with the session, registered by `withRouting(channels: ...)`. The apps sign them at `POST /api/v1/broadcasting/auth`, with the Bearer token, in `routes/api.php` → `BroadcastController::authenticate`. Both use `routes/channels.php`, so change authorization only there. Tests: `tests/Feature/UserPrivateChannelTest.php`, `tests/Feature/ShoppingListRealtimeTest.php`.

## Event shape convention
`final class X implements ShouldBroadcast`, with `broadcastOn()` returning `[new PrivateChannel(...)]`, a dotted `broadcastAs()` (`shopping-list.changed`, `notification.created`, `whatsapp.chat.updated`, `chat.conversation.updated`), and a small `broadcastWith()` of friendly ids and flags. The payload is a hint: clients reload through the API. Never put message bodies or records in it; `WhatsappQrCodeUpdated` is the only exception and goes to its owner only. Example: `app/Events/ShoppingListChanged.php`.

## Listen with a leading dot
Because `broadcastAs()` is custom, Livewire listeners are `'echo-private:'.Channel.',.event.name'` (`app/Livewire/Shopping/ShoppingListShow.php`, `app/Livewire/Notifications/Bell.php`). The native clients bind to the bare name (`shopping-list.changed`). Renaming an event breaks the web, iOS and Android at once: grep `apps/` and `docs/api/v1.yaml` too.

## Queues
Broadcasts are queued on the queue of their domain via `broadcastQueue()`: `notifications` (`CenterNotification::QUEUE`), `whatsapp` (`SyncWhatsappTargets::QUEUE`), `chats` (`ChatConversationUpdated::QUEUE`). Horizon must have a supervisor for that queue (`config/horizon.php`). Use `ShouldBroadcastNow` only for throwaway state that is never stored (`WhatsappChatPresenceChanged`, typing/online).

## Dispatch after commit when the client reloads
Add `ShouldDispatchAfterCommit` when listeners re-read the record (`ShoppingListChanged`), so they never fetch a state that is not saved yet. Dispatch from the Action, never from the controller or the Livewire component, so the web and the API trigger it alike.

## Realtime is optional everywhere
`resources/js/echo.js` registers `window.Echo` only when `VITE_REVERB_APP_KEY` is set, and screens must keep working without it (reload on navigation or poll). The apps reconnect with backoff and re-read on foreground and pull-to-refresh. Do not make a feature depend on receiving an event.

## Config lives in three places
The server reads `BROADCAST_CONNECTION=reverb` and `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `REVERB_SERVER_PORT` (`config/broadcasting.php`, `config/reverb.php`). The web bundle reads `VITE_REVERB_*`. iOS reads `JARVIS_REVERB_*` (`apps/ios/Config/*.xcconfig`) and Android reads `jarvis.reverb*` (`apps/android/jarvis.properties`). Only the public key is shared; never ship `REVERB_APP_SECRET` to a client.

## Native clients speak the Pusher protocol directly
iOS has its own client (`apps/ios/JarvisKit/Sources/Realtime/PusherClient.swift`, protocol 7, over `URLSessionWebSocketTask`), tested by `PusherClientTests`. Android uses `com.pusher:pusher-java-client` with `ApiChannelAuthorizer`, which calls `/broadcasting/auth` through `ApiClient` (`apps/android/app/src/main/java/app/jarvis/android/realtime/`). A new channel needs a subscription in `RealtimeCoordinator` on both apps.

## Testing events
Use `Event::fake([X::class])` and assert `broadcastOn()` names, `broadcastAs()` and `broadcastWith()` exactly, as `tests/Feature/ShoppingListRealtimeTest.php` does. Channel auth tests set `broadcasting.default` to `reverb` with fake keys, call `Broadcast::purge('reverb')`, then `require base_path('routes/channels.php')` (`tests/Feature/UserPrivateChannelTest.php`).
