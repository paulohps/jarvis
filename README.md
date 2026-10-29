# Jarvis

Jarvis is a self-hosted platform for automating a person's and a family's day to day: invoices that issue and send themselves, a WhatsApp and Slack inbox with scheduled messages and AI drafts, a shared agenda and shopping lists, notifications everywhere and voice commands.

It is one monorepo with:

- a **web app** in Laravel 13, Livewire 4 and TallStackUI, installable as a **PWA**;
- a **REST API v1** (Sanctum tokens), described in [`docs/api/v1.yaml`](docs/api/v1.yaml);
- a native **iOS app** ([`apps/ios`](apps/ios), Swift/SwiftUI);
- a native **Android app** ([`apps/android`](apps/android), Kotlin/Jetpack Compose).

The interface is available in English and Brazilian Portuguese (`en`, `pt_BR`).

---

## Contents

- [Modules](#modules)
- [Platform features](#platform-features)
- [Stack](#stack)
- [Running locally](#running-locally)
- [Configuration](#configuration)
- [Integrations](#integrations)
- [Background processes](#background-processes)
- [Mobile apps](#mobile-apps)
- [API](#api)
- [Tests and code quality](#tests-and-code-quality)
- [Deploying to production](#deploying-to-production)
- [Working with AI agents](#working-with-ai-agents)
- [Contributing](#contributing)
- [License](#license)

---

## Modules

The super admin switches each module on or off for each member of each workspace. The super admin has every module in the workspaces they belong to.

| Module | Slug | Web | Apps | Summary |
| --- | --- | :-: | :-: | --- |
| Invoices | `invoices` | ✓ | — | Recurring and one-off invoices, rendered as PDF and sent by e-mail |
| WhatsApp | `whatsapp` | ✓ | ✓ | WhatsApp numbers through the Evolution API: chat, scheduled messages and polls, AI drafts |
| Shopping list | `shopping` | ✓ | ✓ | Shared lists that update live, work offline and can repeat on a schedule |
| Agenda | `reminders` | ✓ | ✓ | Reminders, tasks and events, plus a read-only Google Calendar view |
| Chats | `chats` | ✓ | — | One inbox for WhatsApp and Slack, with scheduled messages, polls and forwards |

### Invoices

- **Payers and issuers.** A payer is who gets billed ("Bill to"). An issuer is who receives the money ("Receiver information"). A workspace can have several of each.
- **Recurring invoices.** Each recurrence picks a payer, an issuer, a currency, an amount, a description, the recipients and the language of the e-mail. The issue date and the due date follow rules: execution day, a day of the current or next month, days after issue, or the last day of the issue month.
- **Sending.** The invoice goes out automatically, or waits for review, where you can edit, approve or discard it. You can also create one-off invoices, "generate and send now" and send an invoice again.
- **Numbering.** `YYYYMM-NNN`, counted per workspace.
- **PDF.** Rendered by Gotenberg with `spatie/laravel-pdf`, always in English, and sent through the user's mail service.
- **History.** Filter by payer, period and status, and download the PDF.

Invoices are web only. They have no API and no tab in the apps.

### WhatsApp

- **Numbers.** Connect one or more numbers through the [Evolution API](https://github.com/EvolutionAPI/evolution-api), by QR code or pairing code. Each number can use its own proxy. Numbers belong to the user, not to the workspace.
- **Contacts and groups.** Synced from the phone.
- **Chat, like WhatsApp Web.**
  - Text, media (loaded on demand), stickers, locations, contacts and polls.
  - Reactions, edits, deletes and replies.
  - Groups, mentions, presence and read ticks.
- **Scheduled messages**, sent once or on a schedule (daily, weekly, monthly or cron). Each one goes to one or more contacts or groups. The body is a template, an AI prompt, or a poll with 2–10 options. "Send now" is available on each one, and every delivery is kept in the history.
- **AI help.** A draft assistant for replies, AI triage of the inbox, tags and priorities, reminders from a chat, and push notifications for new messages.
- **Media.** Downloaded files are kept for `WHATSAPP_MEDIA_KEEP_DAYS` days, then removed by `whatsapp:prune-media`.

### Shopping list

- Shared by the workspace, with live updates through Reverb.
- Drag to reorder.
- In the apps, changes made offline are queued and sent when the connection returns. If two changes conflict, the last one wins.
- One default list receives what you add by voice.
- **Recurring lists:** a list that comes back on a schedule, with a deadline for each run.

### Agenda

- Reminders, tasks and events. Events can last all day or have a duration.
- Repeat rules and a person responsible. That person is notified when it is due, by default in the app, by push and by WhatsApp.
- Month, week and day views, plus a schedule list. The views also show invoices, scheduled WhatsApp messages and shopping deadlines.
- Each user can connect their **Google Calendar**, read-only.

### Chats

- **One inbox** for the user's WhatsApp numbers and Slack workspaces.
  - Pin, priority, nickname and tags on each conversation.
  - Contacts are linked across channels.
  - Bulk actions, AI triage and AI drafts.
- **From the composer:** scheduled messages (once or repeating), polls and forwards.
- Each channel (WhatsApp, Slack) is switched on per workspace and per member.

## Platform features

- **Workspaces.** A workspace holds a family or a group of people. Shopping lists, the agenda and invoices are shared inside a workspace. WhatsApp and Slack accounts, mail services, calendars and devices stay personal. A user can belong to several workspaces.
- **Accounts.**
  - There is no public sign-up. Accounts are created from e-mail invitations, which name a workspace, the modules and a language, and are valid for 7 days.
  - Sign in with a password or with Google.
  - Each app device gets its own Sanctum token; signing out revokes only that device.
  - The super admin can deactivate a user, which ends their sessions and revokes their tokens.
- **Admin area** (super admin only, and also under `/api/v1/admin`): users, invitations, workspaces and their members and modules, AI providers and usage, notification types and templates, and mail services.
- **Notifications.**
  - Channels: in the app (bell and inbox), e-mail, push (Firebase Cloud Messaging, on the apps and the PWA) and WhatsApp.
  - The super admin edits the `en`/`pt_BR` templates and switches types and channels on or off.
  - Each user sets their own preferences.
- **Mail services.** SMTP, Gmail (OAuth) or Resend. A service is either personal or global, set by the super admin. Invoices and notification e-mails go out through it.
- **AI.**
  - The super admin stores provider connections in the database, with encrypted keys. Supported providers: Anthropic, DeepSeek, Gemini, Groq, Mistral, Ollama, OpenAI, OpenAI-compatible, OpenRouter, xAI.
  - Each use (voice commands, WhatsApp replies, chat replies) picks its own connection and model.
  - A usage and price report shows the tokens and the spend.
- **Voice commands**, from the apps (Siri / App Actions) and the web: add or check a shopping item, create or complete a reminder, send a WhatsApp message. Sending is always confirmed first.
- **PWA.** Installable from Chrome, Edge and Android browsers. On iPhone and iPad (iOS 16.4+) it installs from the share sheet, and push there only works once installed. It has an offline page and web push.

## Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.5, Laravel 13, `laravel/ai`, Sanctum, Socialite, Horizon, Reverb |
| Web | Livewire 4, TallStackUI 4, Tailwind CSS 4, Vite 8 (via `vite-plus`) |
| Database | PostgreSQL 18 |
| Queue / cache | Redis |
| PDF | Gotenberg 8 (`spatie/laravel-pdf`) |
| WhatsApp | Evolution API (container, same PostgreSQL and Redis) |
| Push | Firebase Cloud Messaging HTTP v1 (no SDK on the server) |
| Tests | Pest 5, Larastan, Pint |
| Apps | Swift/SwiftUI (iOS 27), Kotlin/Jetpack Compose (Android 14+, minSdk 34) |

## Running locally

Development runs on [Laravel Sail](https://laravel.com/docs/sail), so you need **Docker** and nothing else on the host.

### 1. Clone and install the PHP dependencies

```bash
git clone https://github.com/paulohps/jarvis.git
cd jarvis
cp .env.example .env
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest composer install --ignore-platform-reqs
```

### 2. Start the containers

```bash
./vendor/bin/sail up -d
```

`compose.yaml` starts these services:

| Service | Purpose | Host port (env) |
| --- | --- | --- |
| `laravel.test` | the app | `APP_PORT` (80), `VITE_PORT` (5173), Reverb 8080 |
| `pgsql` | PostgreSQL | `FORWARD_DB_PORT` (5432) |
| `redis` | queues, cache, Horizon | `FORWARD_REDIS_PORT` (6379) |
| `mailpit` | catches every e-mail | `FORWARD_MAILPIT_PORT` (1025), UI on `FORWARD_MAILPIT_DASHBOARD_PORT` (8025) |
| `gotenberg` | renders the invoice PDFs | `FORWARD_GOTENBERG_PORT` (3000) |
| `evolution-api` | WhatsApp | `FORWARD_EVOLUTION_API_PORT` (8081) |

### 3. Install Jarvis

```bash
./vendor/bin/sail composer setup
```

`composer setup` installs the dependencies, creates `.env` if it is missing, runs `php artisan jarvis:install`, then runs `npm install` and `npm run build`.

`jarvis:install` does the following:

1. generates `APP_KEY` when it is empty;
2. runs the migrations;
3. seeds the lookup tables (`LookupSeeder`);
4. provisions the notification types and their default templates;
5. asks for the **super admin**'s name, e-mail and password and creates them with a "Home" workspace.

It is safe to run again. On an installed database it only migrates and refreshes the lookups, and an existing super admin is never touched.

Without a terminal (CI, scripts), pass the super admin as options:

```bash
./vendor/bin/sail artisan jarvis:install --name="Jane Doe" --email=jane@example.com --password="a-long-password"
```

Without a terminal and without the options, it prepares the database and skips the super admin; run it again later to create them. In production the password must have at least 12 characters, mixed case, a number and a symbol, and must not appear in a known data leak.

### 4. Run it

```bash
./vendor/bin/sail composer dev
```

This starts the web server, Vite with hot reload, Horizon, Pail (logs), Reverb and the scheduler (`schedule:work`). Open `APP_URL` (by default <http://localhost>), sign in as the super admin, and invite the other users from **Admin → Invitations**.

Sent e-mails, including invitations and password resets, show up in Mailpit at <http://localhost:8025>.

To reach the Vite dev server through a public domain (for example to test the PWA on a phone), set `VITE_DEV_ORIGIN=https://your-domain`. Vite then binds to `0.0.0.0` and serves hot reload over `wss` on port 443.

## Configuration

Everything is configured through `.env`; [`.env.example`](.env.example) lists every variable. AI keys are not env vars: they are stored, encrypted, in the admin area.

### Application

| Variable | Notes |
| --- | --- |
| `APP_URL` | Public URL. The OAuth callbacks and the default Evolution webhook URL are built from it. |
| `APP_KEY` | Generated by `jarvis:install`. |
| `APP_LOCALE` | Default language for guests: `en` or `pt_BR`. |
| `APP_CONTACT_EMAIL` | Contact shown in the privacy policy and the terms of use. Empty: `MAIL_FROM_ADDRESS`. |
| `SESSION_DRIVER=database` | Keep it on `database`: deactivating a user deletes their sessions. |

### Database, queue and cache

`DB_*` (PostgreSQL), `REDIS_*`, `QUEUE_CONNECTION=redis`, `CACHE_STORE`. With Sail, the defaults in `.env.example` work as they are.

### Mail

`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.

This is the system mailer, used for invitations, password resets and notification e-mails when no mail service applies. Each user can also connect their own SMTP, Gmail or Resend service in the app.

### PDF

`LARAVEL_PDF_DRIVER=gotenberg`, `GOTENBERG_URL` (Sail: `http://gotenberg:3000`).

### Realtime (Reverb)

`BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SERVER_PORT`, `REVERB_SCHEME`, and the matching `VITE_REVERB_*`.

The `VITE_*` values are built into the JavaScript, so changing them means rebuilding the assets. Without `VITE_REVERB_APP_KEY` the web app works, but without live updates.

### Files

`FILESYSTEM_DISK`, `FILESYSTEM_PRIVATE_DISK` (invoice PDFs and other private files) and `FILESYSTEM_MEDIA_DISK` (avatars and WhatsApp media).

Locally both default to the local disks. In production, use the R2 disks (`r2-private`, `r2-public`, with `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_ENDPOINT`, `R2_PRIVATE_BUCKET`, `R2_PUBLIC_BUCKET`, `R2_PUBLIC_URL`), so that the web and Horizon containers see the same files.

### Google (sign-in, Gmail, Calendar)

`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, optional `GOOGLE_REDIRECT_URI` (default `{APP_URL}/auth/google/callback`), `GOOGLE_IOS_CLIENT_ID`, `GOOGLE_ANDROID_CLIENT_ID`. See [Google Cloud](#google-cloud).

### Push (Firebase)

- **Server:** `FIREBASE_CREDENTIALS` (the service account JSON, or the path to its file) and `FIREBASE_PROJECT_ID`.
- **Web push for the PWA:** `FIREBASE_WEB_API_KEY`, `FIREBASE_WEB_AUTH_DOMAIN`, `FIREBASE_WEB_MESSAGING_SENDER_ID`, `FIREBASE_WEB_APP_ID`, `FIREBASE_WEB_VAPID_KEY`. These are public values. If any one is missing, the PWA offers no push.

### WhatsApp (Evolution API)

| Variable | Notes |
| --- | --- |
| `EVOLUTION_API_URL` | Sail: `http://evolution-api:8080`. |
| `EVOLUTION_API_KEY` | Also passed to the container as its `AUTHENTICATION_API_KEY`. |
| `EVOLUTION_WEBHOOK_SECRET` | Sent by Evolution in the `X-Evolution-Secret` header and checked on every webhook. |
| `EVOLUTION_WEBHOOK_URL` | Where Evolution posts. Empty: `{APP_URL}/api/v1/webhooks/evolution`. With Sail: `http://laravel.test/api/v1/webhooks/evolution`. |
| `EVOLUTION_API_TIMEOUT`, `EVOLUTION_API_SYNC_TIMEOUT` | Optional HTTP timeouts. |
| `WHATSAPP_MEDIA_KEEP_DAYS` | Optional: how long downloaded media is kept. |

### Slack

`SLACK_CLIENT_ID`, `SLACK_CLIENT_SECRET`, `SLACK_SIGNING_SECRET`, optional `SLACK_API_TIMEOUT`. See [Slack app](#slack-app).

## Integrations

Every integration is optional. Leave its variables empty and the related features stay hidden or disabled.

### Google Cloud

1. Create an OAuth client of type **Web application** and set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`.
2. Add these authorized redirect URIs:
   - `{APP_URL}/auth/google/callback` — sign in with Google
   - `{APP_URL}/mail-services/google/callback` — connecting Gmail as a mail service
   - `{APP_URL}/agenda/google/callback` — connecting Google Calendar
3. Enable the **Gmail API** and the **Google Calendar API** in the project.
4. For the apps, create an **iOS** and an **Android** OAuth client (`GOOGLE_IOS_CLIENT_ID`, `GOOGLE_ANDROID_CLIENT_ID`). The Android app signs in against the **web** client id.

### Firebase

1. Create a Firebase project and a service account with permission to send messages. Set `FIREBASE_CREDENTIALS` and `FIREBASE_PROJECT_ID`.
2. For web push, add a **Web app** to the project (Project settings → Your apps). Create a VAPID key pair in Cloud Messaging → Web Push certificates. Fill in the `FIREBASE_WEB_*` variables.
3. For the apps, download `GoogleService-Info.plist` and `google-services.json` (see [Mobile apps](#mobile-apps)). For iOS, upload the APNs key to Firebase.

### Evolution API (WhatsApp)

The `evolution-api` service (in `compose.yaml` and in production) uses the app's PostgreSQL, in the `evolution_api` schema, and Redis database 6.

1. Set `EVOLUTION_API_KEY` and `EVOLUTION_WEBHOOK_SECRET` to random values.
2. Connect a number from the WhatsApp screen with a QR code or a pairing code. Jarvis creates the Evolution instance and registers its webhook by itself.

The Evolution API must be able to reach `EVOLUTION_WEBHOOK_URL`.

### Slack app

1. Create a Slack app and set `SLACK_CLIENT_ID`, `SLACK_CLIENT_SECRET` and `SLACK_SIGNING_SECRET`.
2. **OAuth & Permissions:**
   - Redirect URL: `{APP_URL}/chats/slack/callback`.
   - User token scopes, as in `SlackChatProvider::USER_SCOPES`: `channels:history`, `channels:read`, `groups:history`, `groups:read`, `im:history`, `im:read`, `im:write`, `mpim:history`, `mpim:read`, `mpim:write`, `chat:write`, `reactions:read`, `reactions:write`, `users:read`, `files:read`, `files:write`.
3. **Event Subscriptions:**
   - Request URL: `{APP_URL}/api/v1/webhooks/slack`.
   - Subscribe to the user events read by `HandleSlackEvent`: messages, reactions, channel and group changes, `im_created`, member joined/left, `user_change`, `tokens_revoked`, `app_uninstalled`.

If you add a scope later, every user has to reconnect Slack.

## Background processes

| Process | Command | What it does |
| --- | --- | --- |
| Queue workers | `php artisan horizon` | Queues `default`, `notifications`, `invoices`, `whatsapp`, `chats` |
| WebSockets | `php artisan reverb:start` | Live updates for the web app and the apps |
| Scheduler | `php artisan schedule:work` | `recurrences:dispatch` and `chats:send-scheduled` every minute, `whatsapp:prune-media` daily at 04:00 |

`recurrences:dispatch` runs everything that repeats: recurring invoices, scheduled WhatsApp and chat messages, reminders and recurring shopping lists. Each slot runs once and never twice. `composer dev` starts all three processes.

## Mobile apps

The apps talk to the server only through the API v1. They are installed directly from a build; there is no App Store or Play Store distribution. The details are in each app's README.

### iOS — [`apps/ios`](apps/ios/README.md)

- **Requirements:** Xcode 27 on macOS 27 and an Apple developer team. A free Personal Team works, but without push.
- **Configuration:** copy `apps/ios/Config/Local.xcconfig.example` to `Local.xcconfig` (git-ignored) and set:
  - `JARVIS_DEVELOPMENT_TEAM`, `JARVIS_BUNDLE_IDENTIFIER`
  - `JARVIS_API_SCHEME`, `JARVIS_API_HOST`
  - `JARVIS_REVERB_*`
  - `JARVIS_GOOGLE_IOS_CLIENT_ID`, `JARVIS_GOOGLE_REVERSED_CLIENT_ID`

  With a Personal Team, also set `JARVIS_ENTITLEMENTS = Support/JarvisPersonalTeam.entitlements`.
- **Firebase:** put `GoogleService-Info.plist` in `apps/ios/Jarvis/Resources/` (git-ignored).
- **Tests:** `apps/ios/scripts/test.sh`.

### Android — [`apps/android`](apps/android/README.md)

- **Requirements:** Android Studio, or just Docker with `apps/android/scripts/gradle.sh`, which builds inside a JDK 21 + Android SDK container.
- **Configuration:** copy `apps/android/jarvis.properties.example` to `jarvis.properties` and set:
  - `jarvis.apiBaseUrl`
  - `jarvis.reverb*`
  - `jarvis.googleServerClientId` — the server's **web** client id
  - `jarvis.signing.*`
- **Firebase:** put `google-services.json` in `apps/android/app/`.
- **Build:** `apps/android/scripts/gradle.sh assembleDebug` or `assembleRelease`. **Tests:** `apps/android/scripts/gradle.sh test`.

The apps ship pointing at the defaults in `apps/ios/Config/Base.xcconfig` and `apps/android/app/build.gradle.kts`. Override them for your own server.

## API

- Base path: `/api/v1`. Authentication is `Authorization: Bearer <token>`, with a token from `POST /api/v1/auth/login` or the native Google sign-in.
- The contract is [`docs/api/v1.yaml`](docs/api/v1.yaml) (OpenAPI 3.1). `ApiSurfaceTest` checks the contract against the registered routes in both directions, so a route missing from the contract, or the other way round, fails the tests.
- Every resource uses friendly IDs (`usr_…`, `wtgt_…`), never numeric ids. Errors share one format.
- The webhooks live at `/api/v1/webhooks/evolution` and `/api/v1/webhooks/slack`.

## Tests and code quality

```bash
./vendor/bin/sail composer test
```

This runs Pint (style), Larastan (static analysis) and the Pest suite against PostgreSQL. To run a single part:

```bash
./vendor/bin/sail artisan test --compact --filter=InstallJarvis
```

```bash
./vendor/bin/sail composer lint
```

```bash
./vendor/bin/sail composer types:check
```

GitHub Actions ([`.github/workflows/tests.yml`](.github/workflows/tests.yml)) runs the same checks on every pull request.

## Deploying to production

[`.deploy/`](.deploy) holds a single Docker image (`Dockerfile`, `entrypoint.sh`, `php/prod.ini`) and [`.deploy/docker-compose.prod.yml`](.deploy/docker-compose.prod.yml), written for [Dokploy](https://dokploy.com) but usable with any Compose host.

- **Services:**
  - `web` — the app, behind your domain, container port 8080
  - `reverb` — WebSockets, on its own subdomain
  - `horizon`, `scheduler`
  - `gotenberg`, `evolution-api`

  PostgreSQL and Redis run as separate services.
- **On boot**, every container caches the config, routes and events. Only `web` (`RUN_MIGRATIONS=true`) migrates, refreshes the lookups, provisions the notification types and caches the views. The other services wait until `web` is healthy.
- **Assets** are built into the image. Changing a `VITE_*` variable needs a rebuild.
- **Environment:** set the variables from [Configuration](#configuration) with `APP_ENV=production`, `APP_DEBUG=false`, `REVERB_SCHEME=https` and `REVERB_PORT=443`. Use R2 (or another shared disk) for `FILESYSTEM_PRIVATE_DISK` and `FILESYSTEM_MEDIA_DISK`.
- **First deploy:** open a shell in the `web` container and run `php artisan jarvis:install` to create the super admin.

## Working with AI agents

The project's knowledge for AI coding agents lives in [`.ai/`](.ai), in the format of [Laravel Boost](https://github.com/laravel/boost):

- [`.ai/guidelines/`](.ai/guidelines) — the project overview and conventions, merged into each agent's instructions;
- [`.ai/rules/`](.ai/rules) — rules scoped by path. [`index.md`](.ai/rules/index.md) maps globs to rule files;
- [`.ai/skills/`](.ai/skills) — step-by-step workflows: a new module, an API endpoint, a Livewire screen, a notification type, a recurring job, a chat provider capability, an Evolution webhook.

Agent files are generated on each machine and are not committed. Generate yours for the agents you use (Claude Code, Codex, Cursor, Copilot, …) with:

```bash
./vendor/bin/sail artisan boost:install
```

Change `.ai/`, not the generated `CLAUDE.md`, `AGENTS.md`, `.claude/`, `.codex/` and so on.

## Contributing

- Open a pull request against `main`; direct pushes to `main` are blocked. The CI must pass.
- Keep business rules in `app/Actions` and translations in both `lang/en.json` and `lang/pt_BR.json`, and update `docs/api/v1.yaml` when the API changes.
- **When a module is added or changes** (features, services, env vars), update the [Modules](#modules) section and the configuration sections of this README in the same pull request.

## License

MIT.
