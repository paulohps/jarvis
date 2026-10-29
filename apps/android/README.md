# Jarvis — Android app

The native Android client of Jarvis (Kotlin, Jetpack Compose, **minSdk 34 /
Android 14**), installed directly from a signed APK — there is no Play Store
distribution. It talks to the Laravel server only through the API v1
(`docs/api/v1.yaml`).

## Layout

```text
apps/android/
├── app/src/main/java/app/jarvis/android/
│   ├── network        API v1 client (Retrofit + OkHttp), models, 401 → `SessionExpired`
│   ├── auth           Sanctum token, AES-GCM (Android Keystore) in DataStore; sign-in/out
│   ├── i18n           en / pt-BR, the app's locale follows `/me`
│   ├── navigation     tabs from `/me`, router, deep link router
│   ├── push           POST_NOTIFICATIONS, FCM token → `POST /devices`, notifications
│   ├── realtime       Pusher protocol client for Reverb, channels signed with the Bearer
│   ├── notifications  the in-app notifications and the unread badge
│   ├── offline        the offline queue of shopping item changes (DataStore + WorkManager)
│   ├── voice          voice commands: microphone permission, SpeechRecognizer, App Actions
│   │                  mapping, interpreter choice (Gemini Nano / server), answer flows, TTS
│   ├── app            the shell: session state, configuration, wiring
│   └── ui             Compose screens (iOS 27 look on Material 3)
├── app/src/main/res   strings (`values`, `values-pt-rBR`), icons, locale config,
│                      App Actions (`xml/shortcuts.xml`)
├── app/src/test       JUnit suites
├── docker/Dockerfile  JDK 21 + Android SDK 37, for machines without Android Studio
├── scripts/gradle.sh  `./gradlew`, locally or in that container
└── jarvis.properties.example
```

## Requirements

- Android Studio (JDK 17+ and the Android SDK with platform 37), **or** only
  Docker: `scripts/gradle.sh` builds the `jarvis-android-build` image on its
  first run and runs Gradle in it.
- An Android 14+ phone (or emulator) for running the app.
- The Laravel server running (`vendor/bin/sail up -d`) with Reverb.

## Configuration

1. Copy `jarvis.properties.example` to `jarvis.properties` (git-ignored) and fill in:
   - `jarvis.apiBaseUrl`: the server. On a phone, the machine's LAN IP
     (`10.0.2.2` is the host only for the emulator). Plain `http` is allowed
     only when the configured server is `http`;
   - `jarvis.reverb*`: the `REVERB_*` values of the server's `.env`;
   - `jarvis.googleServerClientId`: the **web** OAuth client of the Google Cloud
     project (the server's `GOOGLE_CLIENT_ID`). Credential Manager issues the
     ID token to it, and `POST /auth/google` accepts it as an audience. The
     Google Cloud project also needs an **Android** OAuth client with the
     package `jarvis.campeonato.app` and the SHA-1 of the signing key.
2. Firebase (push): in the Firebase console, add an Android app with the
   package `jarvis.campeonato.app`, download `google-services.json` and put it in
   `app/` (git-ignored). The server sends through FCM with
   `FIREBASE_PROJECT_ID` / `FIREBASE_CREDENTIALS` of the same project. Without
   the file the app runs, without push.

## Build and install

```bash
apps/android/scripts/gradle.sh assembleDebug      # app/build/outputs/apk/debug/
apps/android/scripts/gradle.sh assembleRelease    # app/build/outputs/apk/release/
adb install -r apps/android/app/build/outputs/apk/release/app-release.apk
```

The release APK is signed with the keystore of `jarvis.signing.*` in
`jarvis.properties` (the example shows the `keytool` command that creates
one). Keep the keystore: an update must be signed with the same key. Without
it, `assembleRelease` gives an unsigned APK.

## Tests

```bash
apps/android/scripts/gradle.sh test
```

It runs the JUnit suites: `ApiClientTest`, `TokenStoreTest`,
`LanguagePreferenceTest`, `AppModelTest`, `LoginControllerTest`,
`NavigationBuilderTest`, `DeepLinkRouterTest`, `RouterTest`,
`PushRegistrationTest`, `NotificationsStoreTest`, `ApiChannelAuthorizerTest`,
`OfflineQueueTest`, `ShoppingSyncTest`, `RemindersAndWhatsappTest`,
`VoicePermissionsTest`, `ShortcutMappingTest`, `InterpreterSelectionTest`,
`VoiceResponseHandlerTest`, `SpokenCommandTest`.
The network is a stub interceptor (`StubTransport`); nothing opens a socket.

## How it works

- **Session**: the sign-in (`/auth/login` or `/auth/google`, with the phone's
  name) keeps the Sanctum token encrypted with a key of the Android Keystore.
  Any 401 clears it and the app goes back to the login.
- **Language**: before the sign-in, the phone's; afterwards, the user's
  `locale` from `/me`, set as the app's own locale (per-app language). Every
  call sends it as `Accept-Language`, so the server's messages (e.g.
  "invitation required") come translated.
- **Tabs**: built from `/me`: Shopping, Reminders and WhatsApp when the module
  is on and `is_available_on_mobile`, then Notifications and Settings. `/me` is
  read again when the app comes to the foreground and after any 403, which
  also shows "No access". Invoices and admin never exist in the app.
- **Push**: after the sign-in the app asks `POST_NOTIFICATIONS` once; the FCM
  token goes to `POST /devices` (refused: the device is registered with no
  token). Tapping a push opens its `deep_link`: `jarvis://reminders/{id}`,
  `jarvis://shopping/lists/{id}`, `jarvis://whatsapp`, `jarvis://notifications`,
  or the same paths on the server's web URL.
- **Realtime**: a WebSocket to Reverb (Pusher protocol), the user's private
  channel signed by `POST /broadcasting/auth` with the Bearer token. A
  `notification.created` updates the badge and the list while the app is open.
  It reconnects with backoff, right away when the network comes back, and is
  closed in the background.
- **Shopping** (US-8.1, US-8.2): active and archived lists (create, archive,
  delete); a list with the quick add at the bottom, a tap on the row checks an
  item (with a haptic), the checked ones sink to the end, edit, remove, clear
  checked, rename and archive. The list's private channel
  (`private-shopping-lists.{id}`) reads it again on each `shopping-list.changed`,
  so a mark made on another phone shows up within a second or two.
- **Offline queue**: checking, editing and removing an item are kept in a
  DataStore file with the moment of the tap (`client_updated_at`) and shown at
  once. They are sent in order, each once (removed from the queue only after the
  server answered); what could not reach the server goes to a WorkManager job
  that waits for the network, even with the app closed. The server settles
  conflicts by last write. Adding an item needs the network (sending it twice
  would add it twice). The sign-out clears the queue.
- **Reminders** (US-9.1): Pending / Overdue / Completed with the web's counts;
  complete or reopen from the row or the reminder; create and edit with no
  date, a date and time, or a repetition (daily, weekly, monthly, cron, with an
  end). Dates go on the user's wall clock (`2026-10-06T09:00`), as the web form.
- **WhatsApp** (US-7.3, US-7.5): the scheduled messages (edit, pause, resume,
  cancel a one-time one, delete), scheduling one with the number, contacts,
  groups and typed numbers, sending one now, and the history. Connecting a
  number is web only. A refusal of the server (a time in the past) is shown in
  the form in the user's language (the 422 comes translated).
- **Voice** (US-10.1 to US-10.3): three triggers, one flow, always ending in
  `POST /voice/actions` or `POST /voice/commands` (never a parallel logic).
  - The **microphone button** beside the tab bar asks `RECORD_AUDIO` once and
    listens with the system's `SpeechRecognizer` in the user's language,
    showing the transcript; the recognizer stops after a silence, or on a tap.
    A refusal never crashes: the sheet explains, offers the prompt again while
    Android still shows it, and afterwards the Android settings.
  - **App Actions** (`res/xml/shortcuts.xml`): the built-in intents
    `UPDATE_ITEM_LIST` (add a shopping item) and `CREATE_MESSAGE` (a WhatsApp
    message), and the custom intents `CREATE_REMINDER` / `COMPLETE_REMINDER`
    (Android has no built-in intent for reminders; custom intents are
    understood by the Assistant in **en-US only**). Each one opens the app on
    its action, run with `POST /voice/actions`; one missing a required value
    opens the microphone instead.
  - **Interpreter** (Settings → Voice): *On this phone* (Gemini Nano through
    the ML Kit GenAI Prompt API, the text never leaves the phone), *On the
    server* (`POST /voice/commands`) or *Automatic* (on the phone when Gemini
    Nano is downloaded and ready and the language is supported, otherwise on
    the server; a command the phone does not understand gets a second try on
    the server). The Prompt API is verified by Google for English only, so a
    Portuguese command goes to the server in automatic mode.
  - The answer is shown and spoken (`TextToSpeech`) in the user's language;
    `needs_clarification` lists the options and `needs_confirmation` shows the
    WhatsApp message, its target and account, and sends nothing before the
    tap on Send.
