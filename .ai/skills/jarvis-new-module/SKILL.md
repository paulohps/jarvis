---
name: jarvis-new-module
description: Use when adding a new product module to Jarvis (a new row in the `modules` lookup with its own screens, Actions, API and access rules), from the lookup row to the README.
---

# Adding a product module

A module is a functional unit switched on per workspace member by the super admin. Today: `invoices`, `whatsapp`, `shopping`, `reminders` (shown as "Agenda") and `chats`. Use `shopping` as the reference implementation: it has workspace-scoped data, web screens, API v1, realtime and mobile tabs.

Below, `foo` is the new slug, `Foo` the area name.

## 1. Decide the shape first

- **Whose data is it?** Shared by a workspace (like shopping lists, reminders, invoices) or personal to the user (like WhatsApp/Slack accounts)? This decides `workspace_id` vs `user_id` on every table.
- **Web only or also mobile?** Invoices are web only (`is_available_on_mobile = false`, no API). Shopping, agenda and WhatsApp are on the apps.
- **Slug is forever.** The slug is stored in `modules.slug`, used in route names, `module:` middleware, `/me`, the iOS/Android apps and notification links. Renaming later means keeping the old slug (as `reminders` kept its slug while showing "Agenda").

## 2. The lookup row and constant

1. `app/Models/Module.php`: add `public const string FOO = 'foo';`.
2. `database/seeders/LookupSeeder.php` → `lookups()['modules']`: add `['slug' => 'foo', 'name' => 'Foo', 'is_available_on_mobile' => false|true, 'sort_order' => <next>]`. `sort_order` is the menu order everywhere.
3. For databases already installed, either rerun `php artisan jarvis:install` (safe: migrates and refreshes lookups, never touches the super admin) or add a data migration that upserts the row and flushes `LookupCache`, modelled on `database/migrations/2026_10_08_180000_add_web_device_platform.php`. Prefer the migration when the module's own migrations or code need the row right away.
4. Nothing else is needed for the admin: `WorkspaceDetail::moduleColumns()`, `InviteUser` validation, `GET /api/v1/admin/modules` and the `ModuleEnabled` notification read active modules from the table.

## 3. Schema, models, factories

Follow `.ai/rules/models-and-migrations.md`. For each table:

- `$table->id(); $table->friendlyId();` on anything exposed; model uses `HasFriendlyId` with a new unique prefix of ≤5 chars (grep `function idPrefix` in `app/Models`).
- Workspace data: `foreignId('workspace_id')->constrained()->restrictOnDelete()`, model uses `App\Models\Concerns\BelongsToWorkspace` with `workspaceModule(): string { return Module::FOO; }`, and the table is added to `Workspace::SCOPED_TABLES` (`app/Models/Workspace.php`) so non-empty workspaces cannot be deleted.
- Categorical columns are new lookups (table + `Lookup` subclass + `LookupSeeder` rows), never enums.
- A model used as a morph (`Schedulable` for recurrences, notifiable…) is added to `AppServiceProvider::configureMorphMap()` and to `tests/Feature/MorphMapTest.php`.
- Factories in `database/factories/` use `ResolvesLookups` for lookup FKs and default `workspace_id` to `WorkspaceFactory::household()->id` (see `ReminderFactory`). Add them to the dataset of `tests/Feature/ModuleFactoriesTest.php`.

## 4. Actions and authorization

Follow `.ai/rules/actions.md`.

1. Create `app/Actions/Foo/AuthorizesFooModule.php`, a trait modelled on `app/Actions/Shopping/AuthorizesShoppingModule.php`: `use ResolvesWorkspace;`, `authorizeFoo(User $actor)` calling `Gate::forUser($actor)->authorize('access-module', Module::FOO)`, and a record check calling `authorizeWorkspaceRecord()`.
2. One `final readonly` invokable Action per operation in `app/Actions/Foo/`, each starting with the module check. New records go to `$this->workspaceFor($actor, $input, Module::FOO)`.
3. Queries for a user always use `Model::query()->visibleTo($user)`.
4. Per-user ownership (personal data) goes in a policy in `app/Policies/` (see `WhatsappAccountPolicy`, `DevicePolicy`), auto-discovered by name.

## 5. Web routes and Livewire screens

1. `routes/web.php`, inside the `auth` group:
   ```php
   Route::middleware('module:'.Module::FOO)->prefix('foo')->name('foo.')->group(function (): void {
       Route::livewire('/', FooIndex::class)->name('index');
   });
   ```
   The `foo.index` route name is required: `AppNavigation::moduleItem()` links to `"{$slug}.index"` and `ModuleEnabled` falls back to the dashboard without it. Bind records by friendly id (`HasFriendlyId` does it).
2. Screens in `app/Livewire/Foo/` with `#[Layout('layouts::app-shell')]`, views in `resources/views/livewire/foo/`. Follow the `jarvis-livewire-screen` skill for the UI kit and patterns. Components call the Actions; they never re-implement rules.
3. Realtime: a private channel in `routes/channels.php` authorized with `visibleTo($user)` (see `shopping-lists.{listId}`).

## 6. Navigation and dashboard

`app/Support/Navigation/AppNavigation.php`:

- add the Heroicon to `MODULE_ICONS` (`Module::FOO => '...'`);
- add `Module::FOO => __('Foo')` to `moduleLabel()` — this is the translated name used by the sidebar, tab bar, `/me`, the dashboard cards and the notifications.

The module appears in the sidebar, mobile tab bar and dashboard "Modules" cards automatically (`modulesFor()`, `data-module-card="foo"`). Optional: a dashboard tile/widget in `app/Livewire/Dashboard.php` + `resources/views/livewire/dashboard.blade.php`, gated by `$user->hasModule(Module::FOO)`.

## 7. API v1 (when the module is on the apps)

Follow the `jarvis-api-endpoint` skill. In short:

1. `routes/api.php`, inside `auth:sanctum`: `Route::middleware('module:'.Module::FOO)->prefix('foo')->name('foo.')->group(...)` (route names get the `api.` prefix from the outer group).
2. Controllers in `app/Http/Controllers/Api/V1/Foo/`, resources in `app/Http/Resources/`, exposing `friendly_id`s only; workspace data includes the workspace friendly id and accepts `workspace` on create/move.
3. Document every endpoint and schema in `docs/api/v1.yaml`; `tests/Feature/ApiSurfaceTest.php` compares routes and the spec both ways.
4. A web-only module adds no API routes. If it must never have one, extend the `$webOnly` regex / middleware check in `ApiSurfaceTest` as is done for invoices.

## 8. Mobile availability

- `is_available_on_mobile` is returned per module by `GET /api/v1/me` (`UserResource`) and `GET /api/v1/admin/modules`.
- The apps only show tabs for slugs they know: `apps/android/app/src/main/java/app/jarvis/android/navigation/AppTab.kt` and `apps/ios/JarvisKit/Sources/Navigation/AppTab.swift` (`chats` is flagged `true` but has no tab yet). Setting the flag alone does not add a tab; the app work is separate. Say so in the PR if you do not do it.

## 9. Translations

- Every user-facing string goes through `__()`. Add each new key to **both** `lang/en.json` (key = value) and `lang/pt_BR.json`, including the module label, route titles, validation messages thrown by the Actions and notification texts.
- The `modules.name` column stays English; the UI shows `AppNavigation::moduleLabel()`.

## 10. Tests

Create `tests/Feature/Foo*Test.php` (see `.ai/rules/tests.md`), starting with `$this->seed(LookupSeeder::class)`. Cover at least:

- a user without the module gets 403 on the web route and the API route; a guest is redirected / 401 (pattern: `tests/Feature/ModuleAccessTest.php`);
- a member of another workspace gets 404 on a record; a user in two workspaces sees both; new records go to the default or chosen workspace (pattern: `tests/Feature/ShoppingWorkspacesTest.php`);
- the super admin passes the middleware but sees only their workspaces;
- each Action's happy path and refusals; the Livewire screens; the API JSON shape (`assertExactJson`) and the OpenAPI coverage;
- the factories (`ModuleFactoriesTest`) and morph aliases (`MorphMapTest`) if touched.

Then update the tests that list every module in order: `tests/Feature/ApiMeTest.php`, `tests/Feature/ApiAdminTest.php` (`data.*.slug` and members' modules), `tests/Feature/AppShellNavigationTest.php`, `tests/Feature/DashboardTest.php`.

Run only the affected files: `sudo -n docker exec -u sail jarvis-dev-laravel.test-1 php artisan test --compact tests/Feature/FooTest.php`, then `vendor/bin/pint --dirty` and `vendor/bin/phpstan analyse`.

## 11. Update README.md

The README lists every module. Add the new module to its modules section: what it does for the user, whether it is on web only or also on the apps, and any service or env var it needs (also add those to `.env.example` and the README's setup/env sections). Do it in the same change.

## Checklist

- [ ] `Module::FOO` constant + `LookupSeeder` row (`name`, `is_available_on_mobile`, `sort_order`) + upsert migration or `jarvis:install` rerun for existing databases
- [ ] Migrations with `friendlyId()`; `workspace_id` + `BelongsToWorkspace` + `Workspace::SCOPED_TABLES` for shared data
- [ ] Models (`#[Fillable]`, `casts()`, `@property` docblocks), factories with `ResolvesLookups`, morph map if needed
- [ ] `AuthorizesFooModule` trait; Actions check `access-module` and workspace visibility
- [ ] Web routes under `module:foo` with a `foo.index` route; Livewire screens on `layouts::app-shell`
- [ ] `AppNavigation::MODULE_ICONS` and `moduleLabel()`
- [ ] API v1 routes under `module:foo`, resources with friendly ids, `docs/api/v1.yaml` (or a deliberate web-only decision)
- [ ] `is_available_on_mobile` decided; app tabs done or explicitly left for later
- [ ] All strings in `lang/en.json` and `lang/pt_BR.json`
- [ ] Feature tests for access (403/404/super admin), Actions, screens, API; module lists in existing tests updated
- [ ] README.md modules section (and env/setup) updated
