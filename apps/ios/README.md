# Jarvis — iOS app

The native iOS client of Jarvis (SwiftUI, deployment target **iOS 27**), installed
directly from Xcode or as an ad hoc build — there is no App Store distribution.
It talks to the Laravel server only through the API v1 (`docs/api/v1.yaml`).

## Layout

```text
apps/ios/
├── Jarvis.xcodeproj         the app project (shared scheme `Jarvis`)
├── Jarvis/                  the app target (SwiftUI screens, push, Siri intents, wiring)
├── JarvisKit/               local Swift package with the tested logic
│   ├── Sources/Networking   API v1 client, models, 401 → `sessionExpired`
│   ├── Sources/Auth         Sanctum token in the Keychain, sign-in/out
│   ├── Sources/Localization en / pt-BR String Catalog, language from `/me`
│   ├── Sources/Navigation   tabs from `/me`, deep link router
│   ├── Sources/Push         push permission flow and `POST /devices`
│   ├── Sources/Realtime     Pusher protocol client for Reverb
│   ├── Sources/Offline      offline queue of the shopping item changes
│   ├── Sources/Voice        voice commands: permissions, Siri intent mapping,
│   │                        interpreter choice, answer flows
│   └── Tests/               XCTest suites
├── Config/                  xcconfig (Base + your git-ignored Local)
├── Support/                 Info.plist and entitlements
└── scripts/test.sh          `xcodebuild test` on a simulator
```

## Requirements

- Xcode 27 (iOS 27 SDK, Swift 6.4) on macOS 27. The Command Line Tools alone are
  not enough: XCTest and the String Catalog compiler ship only with Xcode.
- An Apple developer account (for push and for installing on a device).
- The Laravel server running (`vendor/bin/sail up -d`) with Reverb.

The Swift packages (Firebase 12, Google Sign-In 9) are resolved by Xcode on the
first build.

## Configuration

1. Copy `Config/Local.xcconfig.example` to `Config/Local.xcconfig` and fill in:
   - `JARVIS_DEVELOPMENT_TEAM` and, if needed, `JARVIS_BUNDLE_IDENTIFIER`;
   - `JARVIS_API_SCHEME` / `JARVIS_API_HOST`: the server. On a device, use the
     Mac's LAN IP (`localhost` only works in the simulator);
   - `JARVIS_REVERB_*`: the `REVERB_*` values of the server's `.env`;
   - `JARVIS_GOOGLE_IOS_CLIENT_ID` and `JARVIS_GOOGLE_REVERSED_CLIENT_ID`: the
     iOS OAuth client of the Google Cloud project. The server must accept it as
     an audience: set `GOOGLE_IOS_CLIENT_ID` in its `.env` to the same value.
2. Firebase (push): in the Firebase console, add an iOS app with the bundle
   identifier, upload the **APNs authentication key** (Project settings → Cloud
   Messaging), download `GoogleService-Info.plist` and put it in
   `Jarvis/Resources/` (git-ignored). The server sends through FCM with
   `FIREBASE_PROJECT_ID` / `FIREBASE_CREDENTIALS` of the same project.
   Without the file the app runs, without push.

## Build and run

Open `Jarvis.xcodeproj`, pick the `Jarvis` scheme and a device or simulator, Run.

From the command line:

```bash
cd apps/ios
xcodebuild build -project Jarvis.xcodeproj -scheme Jarvis \
  -destination 'generic/platform=iOS Simulator'
```

Ad hoc install: Product → Archive → Distribute App → Release Testing (Ad Hoc),
with the devices registered in the developer account. Push on an ad hoc build
uses the production APNs environment; the APNs key in Firebase covers both.

## Tests

```bash
apps/ios/scripts/test.sh                       # first iPhone simulator found
SIMULATOR="iPhone 17" apps/ios/scripts/test.sh
```

It runs `xcodebuild test -scheme Jarvis`, whose test action holds the JarvisKit
XCTest targets: `APIClientTests`, `TokenStoreTests`, `LanguagePreferenceTests`,
`NotificationItemTests`, `ShoppingListTests`, `RemindersAndWhatsappTests`, `WorkspacesTests`, `TabBuilderTests`, `DeepLinkRouterTests`,
`PushRegistrationTests`, `PusherClientTests`, `OfflineQueueTests`, `IntentMappingTests`,
`InterpreterSelectionTests`, `VoiceResponseHandlerTests`, `VoicePermissionsTests`. The package can also be tested alone:

```bash
cd apps/ios/JarvisKit && swift test     # macOS host, needs Xcode selected
```

## How it works

- **Session**: the sign-in (`/auth/login` or `/auth/google`, with the device's
  name) keeps the Sanctum token in the Keychain. Any 401 clears it and the app
  goes back to the login.
- **Language**: before the sign-in, the device's; afterwards, the user's
  `locale` from `/me`. Every call sends it as `Accept-Language`, so the server's
  messages (e.g. "invitation required") come translated.
- **Tabs**: built from `/me`: Shopping, Reminders and WhatsApp when the module is
  on and `is_available_on_mobile`, then Notifications and Settings. `/me` is read
  again when the app comes to the foreground and after any 403. Invoices
  never exist in the app.
- **Workspaces**: a user sees the lists and reminders of all their workspaces
  together. With two or more workspaces holding a module, its forms ask where
  (the default one first) and its records show the workspace's color; with one,
  nothing changes. Settings → Workspaces sets one's own color and default. The
  super admin also gets Settings → Administration: workspaces and members,
  users, invitations (`/api/v1/admin/*`).
- **Push**: permission → APNs → FCM token → `POST /devices`. Refusing the
  permission only hides push. Tapping a push opens its `deep_link`:
  `jarvis://reminders/{id}`, `jarvis://shopping/lists/{id}`, `jarvis://whatsapp`,
  `jarvis://notifications`, or the same paths on the server's web URL.
- **Realtime**: a WebSocket to Reverb (Pusher protocol 7), private channels
  signed by `POST /broadcasting/auth` with the Bearer token. It reconnects with
  backoff, and right away when the network comes back.
- **Shopping list offline**: checking, editing and removing an item are kept
  on disk (`Application Support/Jarvis/offline-mutations.json`) with the moment
  of the tap, shown at once and sent in order — right away, when the network
  comes back and back in the foreground. The toggle sends that moment as
  `client_updated_at` and the server keeps the last write. Adding an item needs
  the network: an add sent twice would add the item twice.
- **Reminders and WhatsApp**: the same filters, counts and schedules as the web.
  Dates go to the server without an offset, on the user's wall clock (the
  timezone of `/me`); a refused one (in the past) shows the server's message,
  in the user's language. Connecting a WhatsApp number by QR code stays on the
  web: the app links to `/whatsapp/accounts`.
- **Voice**: three triggers, one flow (US-10.1 to US-10.3).
  - The **microphone button** above the tab bar listens with the Speech
    framework in the user's language, showing the transcript while they speak;
    it stops on a tap or after a short silence. Without the microphone or the
    speech recognition permission the sheet explains how to turn it on in the
    iOS Settings.
  - **Siri**: App Intents "Add shopping item", "Create reminder", "Complete
    reminder" and "Send WhatsApp message", said with the app's name ("Siri, add
    a shopping item in Jarvis"). iOS has no custom wake word, and SiriKit was
    deprecated at WWDC 2026. Each intent calls `POST /voice/actions`; Siri asks
    which one on a `needs_clarification` and asks to confirm before a WhatsApp
    send.
  - **Interpreter** (Settings → Voice): *On this iPhone* (Apple Foundation
    Models, the text never leaves the device), *On the server*
    (`POST /voice/commands`) or *Automatic* (on the iPhone when Apple
    Intelligence is on and supports the language, otherwise on the server; a
    command the iPhone does not understand gets a second try on the server).
    Both end in the same server action.
  - The answer is shown and spoken (`AVSpeechSynthesizer`) in the user's
    language; `needs_clarification` lists the options and `needs_confirmation`
    sends nothing before the tap on Send.
