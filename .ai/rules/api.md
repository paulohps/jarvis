---
paths:
  - 'routes/api.php'
  - 'app/Http/Controllers/Api/**'
  - 'app/Http/Resources/**'
  - 'app/Http/Middleware/**'
  - 'docs/api/**'
---

# REST API v1

## Prefix and groups come from `bootstrap/app.php`
`routes/api.php` is mounted with `apiPrefix: 'api/v1'`; never write `v1` in a route. The `api` group appends `SetLocale` and `TrackLastSeen` and is throttled by the `api` limiter (60/min per user, per IP for guests). `EnsureUserIsActive` is web-only: a deactivated user loses their API access because `DeactivateUser` deletes their Sanctum tokens. Other limiters are in `AppServiceProvider::configureRateLimiting()`: `api-auth` (10/min per IP), `voice`, `whatsapp-reply`, `chat-reply` (20/min per user).

## Every authenticated route goes inside the `auth:sanctum` group
Only the webhooks (`VerifyEvolutionWebhookSecret`, `VerifySlackSignature`), the signed file URLs (`api.chats.file`, `api.whatsapp.media`, `signed` + `throttle:api`) and `auth/login` / `auth/google` sit outside it. Name routes `api.<area>.<action>`. Module endpoints go in a `module:<slug>` group (`Module::SHOPPING`, `REMINDERS`, `WHATSAPP`, `CHATS`); super admin endpoints go under `admin/` with `can:super-admin`.

## `ApiSurfaceTest` locks what the API exposes
`tests/Feature/ApiSurfaceTest.php` fails if any `api/v1` URI matches `invoice|issuer|payer|template|connect|qr|ai-provider`, if a route uses `module:invoices`, if `can:super-admin` is used outside `api/v1/admin/` or missing inside it, or if anything other than GET is under `/whatsapp/accounts`. Invoices, templates, AI providers and connecting accounts (WhatsApp QR, Slack, Gmail, Google Calendar) are web-only by design.

## Controllers are thin and call the same Action as Livewire
Gather input with `$request->only([...])`, pass `$request->user()` as the actor, return a Resource. Validation and authorization live in the Action (`Validator::make(...)->validate()`, `Gate::forUser($actor)->authorize(...)`), so web and API answer the same 422/403. There is no `app/Http/Requests`; use inline `$request->validate()` only for API-only fields, as `ShoppingListItemController::toggle()` does. Example: `app/Http/Controllers/Api/V1/Shopping/ShoppingListItemController.php`.

## Public ids only: `friendly_id`
Models using `HasFriendlyId` bind routes by `friendly_id` (`{prefix}_{24 chars}`), and Resources send `friendly_id`. The numeric `id` never appears in a URL or payload. The one exception is `UserResource::broadcast_channel` (`private-App.Models.User.{id}`). Name the route parameter like the model variable (`{list}`, `{item}`, `{device}`), because the OpenAPI path must use the same name.

## Records of others answer 404, switched-off modules answer 403
`bootstrap/app.php` maps `ModelNotFoundException` to a plain `{"message":"Not found."}` with no model name or ids. Actions throw `ModelNotFoundException` for a record outside the actor's workspaces (`AuthorizesShoppingModule::authorizeShoppingItem()`). `EnsureModuleAccess` throws `ModuleAccessDenied` (403). The apps re-read `/me` after any 403, so do not use 403 for a plain validation refusal.

## Error format is fixed
Everything under `api/*` renders JSON: `{message}`, plus `errors` (Laravel format) on a 422. A guest gets a 401 `{"message":"Unauthenticated."}`, never a redirect (`redirectGuestsTo` returns null for `api/*`). Turn provider or domain failures into `ValidationException::withMessages([field => ...])` on the field the client shows, as `ChatMessageController` does with `ChatProviderException`. Both apps map 401/403/404/422/429 one by one. Covered by `tests/Feature/ApiErrorFormatTest.php`.

## Messages are translated per request
`SetLocale` uses the token user's locale, or `Accept-Language` before sign-in. Every `__()` string returned by the API, validation messages included, needs entries in `lang/en.json` and `lang/pt_BR.json`.

## Resources: snake_case, ISO-8601, the `data` envelope
Return `JsonResource` or `::collection()`; clients decode `{ "data": ... }` (`DataEnvelope`). Dates use `->toIso8601String()`, and nullable relations use `whenLoaded`. Extra info goes in `meta` through `->additional(['meta' => ...])` (`NotificationController::index` → `meta.unread_count`; reminders → `meta.timezone`, `meta.counts`). Never return secrets: `DeviceResource` exposes `has_push_token`, never the token. Example: `app/Http/Resources/ShoppingListItemResource.php`.

## Status codes and pagination
Create → 201 (`->response()->setStatusCode(201)`), delete → 204 `response()->noContent()`, upsert → 201 or 200 by `wasRecentlyCreated` (`DeviceController::store`). Paginated lists take `page` and `per_page`, clamped with `min(max($request->integer('per_page', self::PER_PAGE), 1), self::MAX_PER_PAGE)`. Chat messages use cursor-like `before`/`limit` with `meta.has_older` instead.

## Wall-clock dates have no offset
The apps send local times without an offset (`2026-10-06T09:00`), read in the user's timezone from `/me`. Instants go out in ISO-8601. Do not ask clients to convert to UTC.

## Sanctum: one token per device, no abilities, no expiry
`IssueDeviceToken` runs `createToken($deviceName)` with no abilities, and `config/sanctum.php` has `expiration => null`. `POST /auth/login` and `/auth/google` return `{token, user}`, and refusals are 422 (Google refusals land on `id_token`). Logout (`RevokeCurrentToken`) deletes only the current token and its `Device` row, and `DELETE /devices/{device}` (`RevokeDevice`) deletes that device's token. Google ID tokens are checked by `GoogleIdTokenVerifier` against Google's `tokeninfo` endpoint. The accepted audiences are `GOOGLE_CLIENT_ID`, `GOOGLE_IOS_CLIENT_ID` and `GOOGLE_ANDROID_CLIENT_ID`.

## Devices and push tokens
`POST /devices` (`RegisterDevice`) upserts one device per Sanctum token (`personal_access_token_id`). A `push_token` held elsewhere moves to this device. Omitting `push_token` keeps it, and `null` clears it. Push goes only through FCM HTTP v1 (`app/Services/Push/FcmClient.php`, no SDK) via `PushChannel`. A rejected token (`UNREGISTERED`) is nulled on its device. The payload's `data.deep_link` is the web URL of the notification's first action, and the apps map that path to a screen.

## `docs/api/v1.yaml` must match the routes exactly
`ApiSurfaceTest` compares the YAML's `METHOD /path` set with `Route::getRoutes()` under `api/v1` both ways (HEAD and the PUT twin of a PATCH are ignored). It also checks that every `$ref: '#/components/<section>/<Name>'` exists. Path parameters must use the route's names. Add, rename or delete the YAML entry in the same change as the route. The webhooks and signed file routes are documented too.

## The YAML is a shape contract only partly tested
Only `VoiceCommandResult` is checked against real responses (`tests/Feature/VoiceEndpointsTest.php`). The other schemas can drift silently from the Resources, so update `components/schemas/<Name>` whenever you change a Resource's keys. The Swift and Kotlin models are written by hand from these schemas and cite them in their doc comments (`components/schemas/User`).

## Reuse shared components in the YAML
Error responses: `$ref: '#/components/responses/{Unauthenticated|Forbidden|NotFound|ValidationError|TooManyRequests}'`. Path params: `#/components/parameters/<Model>`. Tag each operation with an existing tag (`Auth`, `Profile`, `Devices`, `Notifications`, `Realtime`, `Shopping`, `Reminders`, `WhatsApp`, `Chats`, `Voice`, `Webhooks`, ...). Write `$ref` values in single quotes, because that form is what the ref check parses.

## The YAML `info.description` is partly stale
It still says "the admin area" is web only, but `/admin/*` exists for the super admin and both apps use it. It also lists only some of the rate limits. Fix the text when you touch that section; do not repeat those claims elsewhere.

## Chats endpoints have no native client yet
`/chats/*` (the multi-channel chat screen: WhatsApp and Slack) is documented and tested (`tests/Feature/ApiChatsTest.php`), but neither `apps/ios` nor `apps/android` calls it yet. Controllers look up conversations by string with `FindsChatConversations::conversationOf($user, $id)`, which scopes to the owner, instead of using route model binding.
