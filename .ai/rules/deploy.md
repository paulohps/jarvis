---
paths:
  - '.deploy/**'
  - 'compose.yaml'
  - 'docker-compose.override.yml'
  - '.github/workflows/**'
  - '.dockerignore'
---

# Deploy, Sail and CI

## One image, four app services
`.deploy/Dockerfile` builds one image (`jarvis:latest`) used by `web`, `horizon`, `reverb` and `scheduler` in `.deploy/docker-compose.prod.yml` (Dokploy "Compose" type, Compose Path `.deploy/docker-compose.prod.yml`, build context `..` = repo root). Stages: `composer:2` vendor (`--no-dev --ignore-platform-reqs`), `node:22-bookworm-slim` assets (glibc required by the native rollup/lightningcss/oxide binaries — not alpine), final `dunglas/frankenphp:1.13.2-php8.5`.

## VITE_* are baked at build time
`VITE_APP_NAME`, `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` are build args passed from the Dokploy env. Changing them requires a rebuild, not a restart. The asset stage copies `vendor/` because the CSS imports TallStackUI from `vendor/` and Tailwind scans it via `@source`.

## Runtime is non-root and read-only code
FrankenPHP listens on `:8080` (`SERVER_NAME=:8080`) as `www-data`; only `storage/`, `bootstrap/cache`, `/data`, `/config` are writable. Autoload, `package:discover` and the `public/storage` symlink are done at build time. `.deploy/php/prod.ini` sets `opcache.validate_timestamps=0`, `variables_order=EGPCS`, 100M uploads, errors to stderr: code changes need a new image.

## Entrypoint behaviour
`.deploy/entrypoint.sh` runs `config:cache`, `route:cache`, `event:cache` in every container. Only the container with `RUN_MIGRATIONS=true` (`web`) runs `migrate --force`, `db:seed --class=LookupSeeder --force` (before provisioning: channels are lookups), `notifications:provision` and `view:cache`. `horizon`, `reverb` and `scheduler` `depends_on: web: service_healthy`, so they start after migrations. Keep new boot steps idempotent and web-only unless every container needs them.

## config:cache means no env() outside config
Because config is cached at boot, `env()` called outside `config/*.php` returns null in production. Read settings through `config()`; add new variables to a config file and to `.env.example`.

## Health checks are overridden on purpose
The FrankenPHP base image ships a Caddy-admin healthcheck (`:2019`). `reverb` overrides it with a curl on its own port and `scheduler` disables it; otherwise they are "unhealthy" and Traefik drops the route (404). `web` checks `/up`, `horizon` runs `horizon:status`, `gotenberg` checks `/health`.

## Services and networks
Everything joins the external `dokploy-network`. PostgreSQL and Redis are separate Dokploy services (`DB_HOST`/`REDIS_HOST` = their internal names). `gotenberg` (`gotenberg/gotenberg:8`) and `evolution-api` run in the compose without public domains; Evolution uses the same PostgreSQL (schema `evolution_api`) and Redis db 6, with `DATABASE_SAVE_DATA_NEW_MESSAGE/HISTORIC=true`, mirroring `compose.yaml`. Domains: app → `web:8080`, WebSocket subdomain → `reverb:8080` (TLS at Traefik, `REVERB_PORT=443`, `REVERB_SCHEME=https`; server listens with `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=8080`).

## Shared storage across containers
Invoice PDFs are rendered by Horizon and served by web, so the private disk must be shared: production sets `FILESYSTEM_PRIVATE_DISK=r2-private` and `FILESYSTEM_MEDIA_DISK=r2-public` (`R2_*` vars in `config/filesystems.php`). The `storage_public` volume is mounted only on `web` and `horizon`. Never rely on container-local `storage/app/private` in production.

## The env list in the compose header is incomplete
The comment in `docker-compose.prod.yml` predates several features: production also needs `FILESYSTEM_PRIVATE_DISK`/`FILESYSTEM_MEDIA_DISK` + `R2_*`, `FIREBASE_*` (push, web push), `SLACK_*` (chats), `EVOLUTION_WEBHOOK_URL`, `APP_CONTACT_EMAIL`, and Horizon also serves the `chats` queue. When adding an env var, update `.env.example`, this header and the README.

## Sail for development
`compose.yaml` (tracked): `laravel.test` (Sail PHP 8.5 runtime; ports `APP_PORT` 80, `VITE_PORT` 5173, `REVERB_PORT` 8080), `pgsql` (`postgres:18-alpine`, 5432, creates the `testing` DB), `redis` (6379), `mailpit` (1025 SMTP, 8025 UI), `gotenberg` (3000), `evolution-api` (host 8081 → 8080). Horizon, Reverb and the scheduler are not separate services there: `composer dev` (`php artisan dev`, extended in `AppServiceProvider::configureDevCommands()`) starts them.

## docker-compose.override.yml is local only
It is excluded via `.git/info/exclude` and describes one developer's server (separate `reverb`/`horizon`/`scheduler`/`vite` containers on the Sail image, a Caddy edge on `dokploy-network`). Never commit it, never reference its domains, and don't let it join app services to `dokploy-network`: service names like `reverb`/`gotenberg` would collide with production's.

## CI mirrors production engines
`.github/workflows/tests.yml` runs on push to `main` and PRs with `postgres:18-alpine` and `redis:alpine` services (the schema uses PostgreSQL partial unique indexes — no SQLite), PHP 8.5 + `pdo_pgsql, pgsql, redis, pcntl, posix, intl`, Node 22, then `composer setup`, Pint `--test`, Larastan, `php artisan test --compact`. Real env vars override the `.env` copied from `.env.example` (whose hosts only resolve inside Sail); `BROADCAST_CONNECTION=log`. Actions are pinned by commit SHA; keep it that way.

## `composer setup` runs `jarvis:install`
`composer setup` copies `.env`, runs `php artisan jarvis:install` (key, migrate, lookups, provision, super admin), `npm install` and `npm run build`. Without a TTY and without `--name/--email/--password`, the super-admin step fails validation and returns a failure exit code, which can fail CI's "Setup Application" step; pass the options or make the step non-fatal when touching either.
