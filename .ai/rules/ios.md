---
paths:
  - 'apps/ios/**'
---

# iOS app (SwiftUI + JarvisKit)

## Logic goes in JarvisKit, UI in the app target
`apps/ios/JarvisKit` is a local SwiftPM package (`swift-tools-version: 6.4`, iOS 27 / macOS 27) with libraries `Networking`, `Auth`, `Localization`, `Navigation`, `Push`, `Realtime`, `Offline`, `Voice`. Anything testable (endpoints, models, decoding, routing, queues, mapping) belongs there. `apps/ios/Jarvis/` holds the SwiftUI screens, stores and wiring (`App/AppModel.swift`, `App/Router.swift`, `Features/<Area>/`). The app target has no tests of its own.

## The API is only reached through `API` + `Endpoint`
Each call is a `public static func` on `enum API` that returns `Endpoint<Response>`, defined in `JarvisKit/Sources/Networking/API.swift` or in an `extension API` in a feature file (`WhatsappConversations.swift`, `Workspaces.swift`). Paths are relative to `/api/v1` and start with `/` (`"/shopping/items/\(id)/toggle"`). Screens call `client.send(API.x(...))`; never build a `URLRequest` in the app target. Example: `JarvisKit/Sources/Networking/WhatsappChats.swift`.

## JSON is snake_case through `JSONCoding`
`JSONCoding.decoder` uses `.convertFromSnakeCase` and lenient ISO-8601 dates, and `JSONCoding.encoder` uses `.convertToSnakeCase`. Swift properties are camelCase with no `CodingKeys` (`friendlyId`, `isAvailableOnMobile`). Keep keys aligned with `docs/api/v1.yaml` and reference the schema in the doc comment (`/// components/schemas/WhatsappChat`). Responses are wrapped in `DataEnvelope<T>` or `Page<T>`; use `EmptyResponse` for 204.

## `APIClient` owns session and error handling
`APIClient.send` adds `Accept: application/json`, `Accept-Language` and `Authorization: Bearer`, unless `isAuthenticated: false`, which is used only for `/auth/login` and `/auth/google`. A 401 on an authenticated call clears the Keychain token and emits `SessionEvent.sessionExpired`; a 403 emits `.accessDenied`, after which `/me` is read again. Errors are typed as `APIError` (`.validation(message:errors:)` etc.). Show `displayMessage` / `firstError(for:)` in forms; never parse error JSON again in a screen.

## Server messages are already translated
The server answers in the user's locale, or in `Accept-Language` before sign-in. Show the server's 422 messages as they come. App-side strings live in `JarvisKit/Sources/Localization/Resources/Localizable.xcstrings` (en + pt-BR) and are read with `language.localized("Key")` / `language.text("Key")` (`Jarvis/Design/Localized.swift`), not plain `Text("Key")`, because the language follows `/me`, not the device. Siri/App Intents strings are in `Jarvis/Resources/AppShortcuts.xcstrings` and `Localizable.xcstrings`; the keyboard has its own `JarvisKeyboard/Localizable.xcstrings`.

## Build settings come from xcconfig, never from code
`Config/Base.xcconfig` (committed) is included by the project and ends with `#include? "Local.xcconfig"`; the per-machine `Config/Local.xcconfig` is git-ignored (copy it from `Local.xcconfig.example`). Variables: `JARVIS_BUNDLE_IDENTIFIER`, `JARVIS_DEVELOPMENT_TEAM`, `JARVIS_ENTITLEMENTS`, `JARVIS_API_SCHEME`/`HOST`, `JARVIS_REVERB_SCHEME`/`HOST`/`PORT`/`APP_KEY`, `JARVIS_GOOGLE_IOS_CLIENT_ID`, `JARVIS_GOOGLE_REVERSED_CLIENT_ID`. They reach code through `Support/Info.plist` keys (`JarvisAPIBaseURL`, `JarvisReverbURL`, `JarvisReverbAppKey`, `GIDClientID`) read by `Jarvis/App/AppConfiguration.swift`. URLs are split into scheme and host because `//` starts an xcconfig comment.

## Never commit machine or secret files
`.gitignore` excludes `Config/Local.xcconfig` and `Jarvis/Resources/GoogleService-Info.plist`. Without the Firebase plist the app must still run, only without push. Do not put a real team id, LAN IP or production host in `Local.xcconfig.example` (placeholders like `jarvis.example.com`).

## Entitlements depend on the team
`Support/Jarvis.entitlements` has `aps-environment` and needs a paid Apple Developer Program team. A free Personal Team sets `JARVIS_ENTITLEMENTS = Support/JarvisPersonalTeam.entitlements` (empty: no push). Do not add capabilities only to one of them without checking that the other still signs.

## Files are picked up by synchronized groups
`Jarvis/` and `JarvisKeyboard/` are `PBXFileSystemSynchronizedRootGroup`s in `Jarvis.xcodeproj`, so new Swift files there need no project edit. A new JarvisKit target or test target needs `Package.swift` and, for the test action, the shared `Jarvis` scheme. Remote packages (Firebase 12, Google Sign-In 9) are SwiftPM references in the project.

## The keyboard extension shares the app's Keychain token
`JarvisKeyboard` (a custom keyboard, `UIInputViewController`) drafts WhatsApp replies through `API.whatsappChats()`, `API.whatsappChatMessages(chatId:)` and `API.draftWhatsappReply(messageId:instruction:)`. It reads the same `KeychainTokenStore` (service `app.jarvis.ios.sanctum`) through `keychain-access-groups = $(AppIdentifierPrefix)$(JARVIS_BUNDLE_IDENTIFIER)` in `Support/JarvisKeyboard.entitlements`. It needs `RequestsOpenAccess` (Full Access) for the network and checks `hasFullAccess` first. Keep the extension small: it imports only `Networking` and `Auth`. Example: `JarvisKeyboard/KeyboardModel.swift`.

## Push: FCM token to `POST /devices`
Flow: permission → APNs → Firebase Messaging token → `API.registerDevice` (`platform: "ios"`), in `JarvisKit/Sources/Push/PushRegistration.swift` and `Jarvis/Push/PushCoordinator.swift`. Refusing the permission only hides push. A tap opens `data.deep_link`, resolved by `DeepLinkRouter` (`JarvisKit/Sources/Navigation/DeepLinkRouter.swift`), which reads `jarvis://…` and the same paths on the server's web URL. Unknown paths open in the browser (`.web(URL)`). A new deep-linkable screen needs a route there and a `DeepLinkRouterTests` case.

## Realtime client is home-made
`JarvisKit/Sources/Realtime/PusherClient.swift` speaks the Pusher protocol to Reverb over `URLSessionWebSocketTask` and signs private channels with `API.broadcastingAuth` (Bearer). `Jarvis/App/RealtimeCoordinator.swift` subscribes the user's `broadcast_channel` from `/me` and the channels screens ask for (`events(on:)`). Events are hints: reload through the API.

## Offline queue covers item changes only
`JarvisKit/Sources/Offline` stores `PendingMutation` (`toggle`, `update`, `remove`) in `Application Support/Jarvis/offline-mutations.json` with the tap time, sent in order. The toggle sends that time as `client_updated_at`, and the server keeps the last write. Adding an item is online-only (it is not idempotent). Do not queue other non-idempotent calls.

## Dates on the wall clock
Send local times without an offset (`2026-10-06T09:00`) in the user's `/me` timezone, as the web forms do. Show the server's 422 when a time is refused. Instants from the server are ISO-8601.

## Tabs and modules come from `/me`
`TabBuilder` (`JarvisKit/Sources/Navigation/AppTab.swift`) shows Shopping, Reminders and WhatsApp only when the module is on and `is_available_on_mobile`, then Notifications and Settings. Invoices and Chats have no iOS screens. The super admin gets Settings → Administration (`/admin/*`, `Jarvis/Features/Admin/`).

## Tests: XCTest in JarvisKit, stubbed transport
Run `apps/ios/scripts/test.sh` (`SIMULATOR="iPhone 17"` optional), or `cd apps/ios/JarvisKit && swift test` on macOS with Xcode selected. The network is a `StubTransport` conforming to `HTTPTransport`; each test target has its own copy (`Tests/<Target>Tests/StubTransport.swift`). Decode real JSON in tests (snake_case, as the server sends it) and assert `endpoint.method`, `endpoint.path` and the body. Example: `JarvisKit/Tests/NetworkingTests/WhatsappChatsTests.swift`. This cannot run on the Linux dev box: Xcode is required.

## Production defaults in `Base.xcconfig` are deployment-specific
`Base.xcconfig` holds the bundle id, API host, Reverb host and Reverb public key of one deployment. A fork must override them in `Local.xcconfig`. Never put `REVERB_APP_SECRET`, FCM credentials or Google client secrets in any xcconfig.
