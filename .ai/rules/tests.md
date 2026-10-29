---
paths:
  - 'tests/**'
---

# Tests

## How to run them
Tests run inside the Sail container against PostgreSQL (database `testing`, see `phpunit.xml`). Run only the files you touched: `sudo -n docker exec -u sail jarvis-dev-laravel.test-1 php artisan test --compact tests/Feature/WorkspaceAdminTest.php` (add `--filter='name'` to narrow). Do not run the whole suite unless asked; `composer test` (Pint, PHPStan and every test) is the pre-merge gate.

## Pest, feature tests by default
Tests are Pest closures: `test('...', function () {...})`, sentences describing behaviour (`test('the super admin creates a workspace and lands on its page', ...)`). `tests/Pest.php` binds `Tests\TestCase` and `RefreshDatabase` to `tests/Feature` only; `tests/Unit` is for pure PHP with no framework or database (`ThemeTokensTest`, `DayTimelineTest`). Almost everything belongs in `tests/Feature`.

## Seed the lookups you depend on
The schema starts empty. When the code reads lookups by slug (modules, locales, channels, statuses), start with `beforeEach(fn () => $this->seed(LookupSeeder::class));`. Factories create single missing lookup rows by themselves (`ResolvesLookups`), but a screen listing every module or provider needs the seeder.

## Notification types need provisioning
Code that sends through `NotificationCenter` needs the types: `app(NotificationProvisioner::class)->provisionAll();` after seeding, then usually `Notification::fake()`. See `tests/Feature/WorkspaceAdminTest.php`.

## Build users with the workspace-aware factory states
Use `User::factory()->superAdmin()`, `->withModules([Module::SHOPPING])` (household workspace), `->inWorkspace($workspace, [Module::REMINDERS])`, `->deactivated()`, `->googleOnly()`. Never insert `module_workspace_user` rows by hand when `UserFactory::joinWorkspace()` does it. Use `WorkspaceFactory::household()` to get the shared default workspace.

## Always use module constants
Write `Module::CHATS`, `Locale::EN`, `SocialProvider::GOOGLE`, never string literals for slugs. Adding a module changes exact lists asserted in `tests/Feature/ApiMeTest.php`, `ApiAdminTest.php`, `AppShellNavigationTest.php`, `DashboardTest.php`: update those.

## API tests use real Sanctum tokens
Authenticate the API like the apps do: `$this->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/...')`. Use literal `/api/v1/...` paths or `route('api.…')`. `Sanctum::actingAs()` exists in a few files but real tokens also exercise token revocation and `TrackLastSeen`. Assert JSON shapes with `assertExactJson` / `assertJsonPath`, and check that friendly ids, never numeric ids, are returned.

## Livewire tests
Test components with `Livewire::actingAs($user)->test(Component::class, [...])->set(...)->call(...)->assertHasErrors([...])`, and that the route renders with `$this->actingAs($user)->get(route(...))->assertSeeLivewire(Component::class)`. `tests/Pest.php` adds `assertSeeInIsland()` / `assertDontSeeInIsland()` for islands. Prefer asserting `data-*` attributes the views emit (`data-workspace="wsp_…"`, `data-navigation-item="…"`) over visible text.

## Test Actions directly when there is no screen
Call Actions from the container: `app(GenerateInvoice::class)($actor, ...)`. Assert both the happy path and each documented refusal (`AuthorizationException`, `ValidationException` with its key, 404 for invisible records).

## Access rules are part of every feature
For every module endpoint or screen, cover: without the module → 403; another workspace's record → 404; super admin passes every `module:` check but sees only data of workspaces they are a member of. Examples: `tests/Feature/ModuleAccessTest.php`, `tests/Feature/ShoppingWorkspacesTest.php` (web, API and broadcast channel each checked per workspace).

## No network
External APIs are faked over `Http::fake()` by fixtures in `tests/Fixtures`: `Slack\FakeSlackApi::install()`, `Whatsapp\FakeEvolutionApi::install()`, `Push\FakeFcm::install()`. They call `Http::preventStrayRequests()`, so an unexpected request fails the test. Add new integrations' fakes there in the same style (constants for ids and tokens, `$overrides` by endpoint).

## Fixtures for framework seams
`tests/Fixtures` also holds throwaway models and notifications: `FriendlyRecord` (creates its own table), `ScheduleProbe` (not in the morph map on purpose), `LocalizedUser`, `Notifications/*Notice`, `RecurrencePickerHost`. Put new helpers there under the `Tests\Fixtures` namespace, not inline anonymous classes in many files.

## Time
`Date::use(CarbonImmutable::class)` is global. Freeze or move time with `$this->travelTo(...)` / `$this->freezeTime()` for schedules, invitation expiry (`Invitation::VALIDITY_DAYS`) and lockouts; store and assert UTC, display in the user's `timezone`.

## Guard tests that enforce conventions
Some tests fail when a convention is broken: `ApiSurfaceTest` (every `/api/v1` route documented in `docs/api/v1.yaml` both ways, `/api/v1/admin/*` behind `can:super-admin`, no invoices on the API), `MorphMapTest`, `FriendlyIdTest`, `CoreFactoriesTest` / `ModuleFactoriesTest` (every factory creates valid rows), `InstallJarvisTest`. Extend their datasets when you add a model, factory or route; do not weaken them.

## Translations in assertions
The default locale is `en`; assert English text or `__('...')`. For pt_BR, create the user with `'locale_id' => Locale::idFor(Locale::PT_BR)` and assert the translated string, as `AppShellNavigationTest` does with "the shell answers in the user language".
