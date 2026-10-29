# Jarvis

Jarvis is a self-hosted personal and family automation platform: a Laravel web app (Livewire + TallStackUI, installable as a PWA), a REST API v1 (Sanctum) and native iOS (`apps/ios`, Swift/SwiftUI) and Android (`apps/android`, Kotlin/Compose) apps, all in this monorepo.

## Modules

Product features are grouped in modules, rows of the `modules` lookup (`database/seeders/LookupSeeder.php`): `invoices`, `whatsapp`, `shopping`, `reminders` (shown as "Agenda") and `chats`. Access is per workspace membership; the super admin has every module. Cross-cutting areas: workspaces and invitations, notifications (database, mail, push, WhatsApp), mail services, AI providers, voice commands, recurrences.

- When a module is added, or a module gains a feature, a service or an env var, update `README.md` in the same change (its modules section and, when they change, setup and env). Use the `jarvis-new-module` skill to add a module.

## Where things live

- `app/Actions/<Area>/*` — the business operations (one invokable class each), shared by Livewire, the API, jobs and commands. Controllers and components stay thin.
- `app/Services/<Area>/*` — integrations and engines (Evolution API, Slack, Google, FCM, PDF, recurrences, templates, AI).
- `app/Livewire/<Area>/*` + `resources/views/livewire/<area>/*` — the web screens; `resources/views/components/ui/*` — the in-house design system.
- `app/Http/Controllers/Api/V1/*` + `app/Http/Resources/*` — API v1, described in `docs/api/v1.yaml` (kept in sync and tested).
- `routes/console.php` — the scheduler: `recurrences:dispatch` and `chats:send-scheduled` every minute, `whatsapp:prune-media` daily.
- `lang/en.json`, `lang/pt_BR.json` — every user-facing string goes through `__()` with both translations.

## Conventions

- Public identifiers are friendly IDs (`usr_…`, `wtgt_…`; `HasFriendlyId`, `$table->friendlyId()`): never expose numeric ids in URLs, API payloads or the apps.
- Lookups (statuses, frequencies, channels, providers…) are rows matched by `slug`, cached by `App\Support\LookupCache`; add new values to `LookupSeeder`, which is idempotent and runs on every deploy.
- Morph columns use the short aliases of `Relation::enforceMorphMap` in `AppServiceProvider`; a new morphable model must be added there.
- Data is shared per workspace; WhatsApp accounts and push devices are per user.
- Times are stored in UTC and shown/scheduled in the user's timezone.

## Running things

Development runs on Sail (`compose.yaml`: app, pgsql, redis, mailpit, gotenberg, evolution-api). `composer dev` starts the server, Vite, Horizon, Reverb and the scheduler. `php artisan jarvis:install` prepares a new database and creates the super admin. Before finishing a change, run the affected tests (`php artisan test --compact --filter=...`), `vendor/bin/pint --dirty` and `vendor/bin/phpstan analyse`; `composer test` runs all of them.

## Project rules and skills

Path-scoped project rules live in `.ai/rules/` — before editing a file, read `.ai/rules/index.md` and the rule file whose globs match its path. Workflows for recurring tasks (new module, API endpoint, Livewire screen, notification type, recurring job, chat provider capability, Evolution webhook) are skills in `.ai/skills/`.

Agent files (CLAUDE.md, AGENTS.md, `.claude/`, `.codex/`, `.cursor/`, `.mcp.json`, `boost.json`…) are generated locally by `php artisan boost:install` from `.ai/` and are gitignored: change `.ai/`, never the generated files.

## Commits

Commit messages are `type(scope): <one long sentence describing the user-visible change>` (e.g. `feat(chats): …`, `fix(whatsapp): …`, `chore(dev): …`), written about the behaviour, not the files.
