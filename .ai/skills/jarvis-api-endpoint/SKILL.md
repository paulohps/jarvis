---
name: jarvis-api-endpoint
description: Use when adding or changing a Jarvis REST API v1 endpoint end-to-end, from route, controller, Action, Resource, errors, OpenAPI contract and Pest tests to the iOS (JarvisKit) and Android (JarvisApi) clients and their tests.
---

# Adding or changing an API v1 endpoint

The API is the contract of the native apps. It must call the **same Action** as the Livewire screen, be listed in `docs/api/v1.yaml` (checked both ways by `tests/Feature/ApiSurfaceTest.php`) and be consumed by hand-written clients in `apps/ios` and `apps/android`. Do the steps in order; a change is not done until the contract and both clients agree.

Commands run inside Sail on this machine:

```bash
sudo -n docker exec -u sail jarvis-dev-laravel.test-1 php artisan route:list --path=api/v1
sudo -n docker exec -u sail jarvis-dev-laravel.test-1 php artisan test --compact --filter=ApiSurfaceTest
sudo -n docker exec -u sail jarvis-dev-laravel.test-1 vendor/bin/pint --dirty
```

## 1. Decide whether it belongs in the API at all

- Web-only by design (and enforced by `ApiSurfaceTest`): invoices, issuers, payers, templates, AI providers, connecting any account (WhatsApp QR, Slack, Gmail, Google Calendar). Do not add these to the API.
- Super admin endpoints go under `admin/` inside the `can:super-admin` group, and only there.
- WhatsApp accounts are read-only in the API (GET only under `/whatsapp/accounts`).

## 2. Find or write the Action

- Look in `app/Actions/<Area>/` for the Action the Livewire component already uses. Reuse it; never copy its rules into the controller.
- If none exists, write one there (see `.ai/rules/actions.md`): `final readonly class`, `__invoke(User $actor, ..., array $input)`, `Validator::make(...)->validate()` and `Gate::forUser($actor)->authorize(...)` inside it. Make the Livewire component call it as well.
- Records outside the actor's workspaces throw `ModelNotFoundException`, which becomes a 404 `{"message":"Not found."}`. Use 403 only for "not allowed" (module off, not owner), because the apps re-read `/me` after a 403.
- If the change must reach other screens live, dispatch the broadcast event from the Action (see `.ai/rules/realtime.md`).

## 3. Route — `routes/api.php`

- Put it inside `Route::middleware('auth:sanctum')->name('api.')`, in the group of its module (`module:'.Module::SHOPPING` etc.) with that group's prefix and name prefix.
- Never write `v1` in the path: `bootstrap/app.php` sets `apiPrefix: 'api/v1'`.
- Name the parameter after the bound model (`{list}`, `{item}`, `{reminder}`); binding uses `friendly_id` via `HasFriendlyId`.
- Add `->middleware('throttle:...')` for expensive or abusable calls: a named limiter (`voice`, `whatsapp-reply`, `chat-reply` in `AppServiceProvider::configureRateLimiting()`) or an inline one like `throttle:30,1`.
- Name it `api.<area>.<thing>` and keep the comment style of the neighbouring block.

## 4. Controller — `app/Http/Controllers/Api/V1/<Area>/`

- One method per route, with a docblock giving the `METHOD /api/v1/path` and the accepted fields, as in `app/Http/Controllers/Api/V1/Shopping/ShoppingListItemController.php`.
- Inject the Action as a method argument; pass `$request->user()` and `$request->only([...])`.
- Inline `$request->validate()` only for API-only inputs (e.g. `client_updated_at` on toggle). There are no FormRequests.
- Map driver or domain failures to `ValidationException::withMessages([field => $e->getMessage()])` on the field the app shows (`ChatMessageController`).
- Status: 201 for create (`->response()->setStatusCode(201)`), 204 `response()->noContent()` for delete, 200 otherwise. For an upsert, use 201/200 by `wasRecentlyCreated` (`DeviceController::store`).
- Paginate with `page` + `per_page` clamped to the controller's `MAX_PER_PAGE`; extra data goes in `->additional(['meta' => [...]])`.

## 5. Resource — `app/Http/Resources/`

- Reuse the existing Resource if the shape is the same as elsewhere; the apps share models across endpoints.
- Keys in snake_case, `friendly_id` (never `id`), dates as `->toIso8601String()`, relations via `whenLoaded`. Eager-load in the controller (`->load([...])`).
- Never return secrets or internal ids; expose flags instead (`DeviceResource::has_push_token`).
- Adding a key is safe for the apps (both ignore unknown keys). **Removing or renaming** one breaks shipped apps that are installed directly and not auto-updated, so add the new key first and keep the old one until both apps are updated.

## 6. Translations

Every new `__()` string the endpoint can return, including messages thrown from the Action, goes into both `lang/en.json` and `lang/pt_BR.json`. `SetLocale` answers in the user's locale, or in `Accept-Language` before sign-in.

## 7. OpenAPI — `docs/api/v1.yaml`

- Add the path under `paths:` exactly as the route URI minus `api/v1` (`/shopping/items/{item}/toggle`), with the same parameter names. A PATCH route needs only `patch:` (its PUT twin is ignored).
- Use an existing tag; put path params in `parameters:` with `$ref: '#/components/parameters/<Model>'`.
- Responses: reuse `'#/components/responses/{Unauthenticated|Forbidden|NotFound|ValidationError|TooManyRequests}'` and add a shared response (`<Thing>Saved`) when several operations return the same envelope.
- Add or update `components/schemas/<Name>` to match the Resource keys. Only `VoiceCommandResult` is checked against real output, so the other schemas rely on you.
- Write every `$ref` in single quotes: the ref-existence check parses `$ref: '#/components/<section>/<Name>'`.
- Renaming or removing a route requires removing its YAML entry, since the check runs both ways.

## 8. Pest tests — `tests/Feature/Api<Area>Test.php`

Follow `tests/Feature/ApiShoppingTest.php` / `ApiDevicesTest.php`:

```php
beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->user = User::factory()->withModules([Module::SHOPPING])->create();
    $this->token = $this->user->createToken('iPhone')->plainTextToken;
});

test('...', function () {
    $this->withToken($this->token)->postJson('/api/v1/...', [...])
        ->assertCreated()
        ->assertJsonPath('data.friendly_id', ...);
});
```

Cover at least:
- the happy path and the response shape (`assertJsonPath`, `assertJsonMissingPath` for anything secret);
- the same 422 as the Livewire form (assert both, as `ApiShoppingTest` does: "same actions as livewire");
- 401 without a token, 403 with the module off, 404 for another workspace's or user's record;
- the throttle, if you added one;
- `Event::fake([...])` + `Event::assertDispatched` if it broadcasts.

Then run `--filter=ApiSurfaceTest` and your file. Do not run the whole suite unless asked.

## 9. iOS client — `apps/ios/JarvisKit`

1. **Endpoint**: add a `public static func` to `enum API` in `JarvisKit/Sources/Networking/API.swift`, or to the `extension API` of the feature file (`WhatsappConversations.swift`, `Workspaces.swift`...):
   ```swift
   public static func toggleShoppingItem(id: String, checked: Bool, clientUpdatedAt: Date?) -> Endpoint<DataEnvelope<ShoppingListItem>> {
       Endpoint(method: .post, path: "/shopping/items/\(id)/toggle", json: ...)
   }
   ```
   The path starts with `/` and is relative to `/api/v1`. Use `isAuthenticated: false` only for sign-in, `form: MultipartForm` for files, and `EmptyResponse` for 204.
2. **Models**: `Decodable, Equatable, Sendable` structs with camelCase properties (the decoder converts from snake_case), documented with `/// components/schemas/<Name>`. Make fields the server may omit optional. Input structs go in `Inputs.swift` (encoded to snake_case).
3. **Tests**: in `JarvisKit/Tests/NetworkingTests/` (or the matching target), decode a real snake_case JSON sample and assert `endpoint.method`, `endpoint.path` and the JSON body. See `WhatsappChatsTests.swift`.
4. **UI**: the screen/store in `apps/ios/Jarvis/Features/<Area>/` calls `client.send(API.x(...))`, shows `APIError.displayMessage` / `firstError(for:)`, and uses `language.localized("...")` with new keys in `JarvisKit/Sources/Localization/Resources/Localizable.xcstrings` (en + pt-BR).
5. Run `apps/ios/scripts/test.sh` on a Mac with Xcode. On the Linux box you cannot; say so in your summary.

## 10. Android client — `apps/android`

1. **Endpoint**: add a `suspend fun` to `app/src/main/java/app/jarvis/android/network/JarvisApi.kt`:
   ```kotlin
   @POST("shopping/items/{id}/toggle")
   suspend fun toggleShoppingItem(@Path("id") id: String, @Body body: ShoppingItemToggle): DataEnvelope<ShoppingListItem>
   ```
   Do not use a leading `/`. Sign-in endpoints only get `@retrofit2.http.Headers(ApiClient.NO_AUTH_HEADER)`.
2. **Models**: `@Serializable` data classes in the matching `network/*Models.kt`, with `@SerialName("snake_key")` on every multi-word key, defaults for fields older servers lack, and KDoc citing the schema. For a PATCH where omitted ≠ null, send a `JsonObject` body built by the input's `body()`, because `explicitNulls = true` sends nulls.
3. **Tests**: under `app/src/test/java/app/jarvis/android/...`, with `StubTransport` (`transport.enqueue(200, json)`, `transport.client()`), asserting the call result and `transport.requests.last().url` / `transport.bodies.last()`. See `network/WorkspacesTest.kt`.
4. **UI**: the store in `ui/<area>/` calls `client.call { ... }` and catches `ApiException`; add strings to `res/values/strings.xml` and `res/values-pt-rBR/strings.xml`.
5. Run `apps/android/scripts/gradle.sh test`. It works on the Linux box through Docker. If a new model sits outside `app.jarvis.android.**` or a new library uses reflection, also run `assembleRelease` (R8).

## 11. Realtime and push (only if the endpoint changes shared state)

- Broadcast from the Action. If a new event name appears, subscribe or bind it in `apps/ios/Jarvis/App/RealtimeCoordinator.swift` and `apps/android/.../realtime/RealtimeCoordinator.kt`, and mention it in the YAML tag description.
- A new push deep link needs a route in `JarvisKit/Sources/Navigation/DeepLinkRouter.swift` and `navigation/DeepLinkRouter.kt`, each with a test.

## Checklist

- [ ] Endpoint is not a web-only area; super admin routes live only under `admin/` + `can:super-admin`
- [ ] Same Action as the Livewire screen; validation and authorization inside it
- [ ] Route in the right `auth:sanctum` / `module:` group, named `api.*`, throttled if expensive
- [ ] Thin controller, correct status codes, domain errors mapped to 422 on a field
- [ ] Resource uses `friendly_id`, ISO-8601 dates, no secrets; no key removed or renamed while old apps are in use
- [ ] New messages in `lang/en.json` and `lang/pt_BR.json`
- [ ] `docs/api/v1.yaml` path, params, responses and schemas updated; `$ref`s in single quotes
- [ ] Pest: happy path, 401, 403 (module off), 404 (others' record), 422 matching Livewire
- [ ] `ApiSurfaceTest` and the new test pass; `pint --dirty` clean
- [ ] iOS `API` func + models + XCTest (or noted as not run without Xcode)
- [ ] Android `JarvisApi` fun + `@SerialName` models + JUnit test passing via `scripts/gradle.sh test`
- [ ] Realtime subscriptions and deep links updated on both apps if relevant
