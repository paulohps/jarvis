---
paths:
  - 'apps/android/**'
---

# Android app (Kotlin + Compose)

## One package, layered by folder
Everything lives in `app/src/main/java/app/jarvis/android/`: `network` (Retrofit API, models), `auth`, `i18n`, `navigation`, `push`, `realtime`, `notifications`, `offline`, `voice`, `keyboard`, `app` (shell, `AppContainer`, `AppModel`, `AppConfiguration`) and `ui/<area>` (Compose screens plus their `*Store` state holders). The namespace is `app.jarvis.android`; the `applicationId` in `app/build.gradle.kts` is deployment-specific and also serves as the Firebase/Google package name.

## Every endpoint is a `suspend fun` on `JarvisApi`
Add calls to `network/JarvisApi.kt` with Retrofit annotations and paths relative to `api/v1/` with **no leading slash** (`@POST("shopping/items/{id}/toggle")`). A leading `/` would drop the `/api/v1` base. Sign-in calls carry `@Headers(ApiClient.NO_AUTH_HEADER)`, so they go without the token and a 401 there is not a lost session. Call them through `client.call { someEndpoint(...) }`, which turns every failure into an `ApiException`.

## Models use explicit `@SerialName`
`ApiClient.json` has no naming strategy: each snake_case key needs `@SerialName("friendly_id")`. It runs with `ignoreUnknownKeys`, `coerceInputValues`, `explicitNulls = true` and `encodeDefaults = true`, so give new response fields defaults (old servers and cached `/me` lack them) and expect request fields set to `null` to be **sent** as `null`. Wrap responses in `DataEnvelope<T>` / page types, and cite the schema in KDoc (`/** components/schemas/User */`). Example: `network/Models.kt`.

## Partial updates send a `JsonObject`
Because data classes always serialize every field, PATCH endpoints where "absent" and `null` mean different things take `@Body body: JsonObject` built by the input's `body()` (`updateWorkspacePreference`, `updateWhatsappConversation`, `updateWhatsappChatSettings`). Server semantics depend on that: `POST /devices` treats an omitted `push_token` as "keep" and `null` as "clear".

## `ApiClient` owns the headers and the session
Interceptors added at index 0/1 set `Accept`, `Accept-Language` and `Authorization: Bearer`. A 401 on a call that sent a token clears `TokenStore` and emits `SessionEvent.SessionExpired`; a 403 emits `AccessDenied`, after which `/me` is re-read and "No access" can show. Errors are `ApiException.{Unauthenticated, Forbidden, NotFound, Validation, TooManyRequests, Server, Transport, Decoding}`. Show `Validation` messages as they come, because the server already translates them.

## Release builds are minified
`isMinifyEnabled = true` with `app/proguard-rules.pro`, which keeps `@Serializable` serializers under `app.jarvis.android.**`, the `JarvisApi` interface and the Pusher/Java-WebSocket classes. A model outside that package or a new reflection-based library needs a rule. Check `assembleRelease`, not only debug.

## Config comes from `jarvis.properties`, never from code
`app/build.gradle.kts` reads `jarvis.properties` (git-ignored; copy `jarvis.properties.example`) or `JARVIS_<KEY>` env vars into `BuildConfig`: `API_BASE_URL`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_USE_TLS`, `REVERB_APP_KEY`, `GOOGLE_SERVER_CLIENT_ID`. These are read by `app/AppConfiguration.kt`. `usesCleartextTraffic` turns on only when the API is `http://` or Reverb runs without TLS. The defaults in `build.gradle.kts` point at one deployment; forks override them in `jarvis.properties`.

## Firebase is optional at build time
The `google-services` plugin is applied only if `app/google-services.json` exists (git-ignored, like `jarvis.properties`, `*.jks`, `*.keystore`). Without it the app builds and runs without push. Never commit any of these.

## Google sign-in uses the WEB client id
Credential Manager (`ui/login/GoogleSignInProvider.kt`) requests an ID token for `jarvis.googleServerClientId`, which is the server's `GOOGLE_CLIENT_ID`, not the Android client. The Android OAuth client (package + SHA-1) must still exist in the Google Cloud project. `POST /auth/google` accepts it because the server lists all three audiences.

## Push: FCM token, two channels, deep links
`push/JarvisMessagingService.kt` sends new tokens to `POST /devices` (`platform = "android"`, `push/PushRegistration.kt`). `push/NotificationPresenter.kt` creates the channels `jarvis_notifications` (also the manifest's FCM default) and `whatsapp_messages`, matching the server's `WhatsappMessageReceived::ANDROID_CHANNEL`. Renaming a channel on either side silently drops the grouping. Taps resolve `data.deep_link` through `navigation/DeepLinkRouter.kt` (`jarvis://…` or the server's web paths).

## Realtime via pusher-java-client
`realtime/RealtimeCoordinator.kt` connects with `com.pusher:pusher-java-client` and `ApiChannelAuthorizer`, which signs private channels via `POST broadcasting/auth` with the Bearer token. It subscribes the user's `broadcast_channel` from `/me` and, while a screen collects `events(channel, names)`, other private channels (`private-shopping-lists.{id}`). It disconnects in the background. Events are hints: reload through the API.

## Offline queue: item toggles, edits and removals only
`offline/` stores `PendingMutation`s (`ShoppingItemChange.Toggle/Update/Remove`) in DataStore with the tap instant, sends them in order and keeps each until the server answers. `ShoppingSyncWorker` (WorkManager) retries when the network returns. Adding an item stays online-only (not idempotent), and sign-out clears the queue.

## The reply keyboard runs in the app's process
`keyboard/ReplyKeyboardService.kt` is an `InputMethodService` (`BIND_INPUT_METHOD`, `res/xml/reply_keyboard.xml`). It uses the app's own `ApiClient` and token to list WhatsApp chats and draft a reply (`whatsapp/chats`, `chat-messages/{id}/reply`). It is its own lifecycle/saved-state owner for Compose. Logic lives in `ReplyKeyboardStore` (tested by `ReplyKeyboardStoreTest`).

## Strings and locale
User-facing text goes in `res/values/strings.xml` and `res/values-pt-rBR/strings.xml` (`androidResources.localeFilters` = `en`, `pt-rBR`), read with `stringResource(R.string.x)`. The app locale follows `/me` (`i18n/LanguagePreference.kt`, per-app language), not only the system.

## Tabs and modules come from `/me`
Shopping, Reminders and WhatsApp tabs show when the module is on and `is_available_on_mobile`, followed by Notifications and Settings (`navigation/AppTab.kt`). The super admin also gets an Administration section in Settings (`ui/admin/`, `/admin/*`). Invoices and Chats have no Android screens.

## Tests: JUnit with a stub interceptor
`apps/android/scripts/gradle.sh test` runs `./gradlew test` locally, or inside the `jarvis-android-build` Docker image (JDK 21, SDK 37) when there is no local SDK; it works on the Linux dev box. Network tests use `support/StubTransport` (the last OkHttp interceptor): `transport.enqueue(200, json)`, `transport.client()`, then assert `transport.requests` / `transport.bodies`. Nothing opens a socket. Example: `app/src/test/java/app/jarvis/android/network/WorkspacesTest.kt`.

## Toolchain
AGP 9.4, Kotlin 2.4, compileSdk/targetSdk 37, minSdk 34, Java 17 bytecode; versions live in `gradle/libs.versions.toml`. Add dependencies to the catalog, not as string coordinates in `build.gradle.kts`.
