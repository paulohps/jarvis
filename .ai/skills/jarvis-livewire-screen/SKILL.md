---
name: jarvis-livewire-screen
description: Use when adding a new web screen or page to Jarvis (route, full-page Livewire component, layout, design-system views, module gate, en/pt_BR strings, mobile/PWA concerns and Pest tests) in the house style.
---

# Building a Jarvis web screen

Reference screens to copy from:
- list + sheets: `app/Livewire/Shopping/ShoppingListIndex.php` + `resources/views/livewire/shopping/shopping-list-index.blade.php`
- detail + realtime: `app/Livewire/Shopping/ShoppingListShow.php`
- heavy list with an island: `app/Livewire/Chats/ChatsPage.php` + `resources/views/livewire/chats/chats-page.blade.php`
- tests: `tests/Feature/ShoppingListScreensTest.php`

## 1. Put the rule in an Action first

The screen is a client of `app/Actions/<Module>/<Verb><Thing>.php`, which the API controller also calls. Validation and authorization live in the Action (it throws `ValidationException`). If the screen needs an operation the API lacks, add the Action, not component logic. Records are addressed by `friendly_id`.

## 2. Route

In `routes/web.php`, inside `Route::middleware('auth')`, in the module's group:

```php
Route::middleware('module:'.Module::SHOPPING)->prefix('shopping')->name('shopping.')->group(function (): void {
    Route::livewire('/', ShoppingListIndex::class)->name('index');
    Route::livewire('/lists/{list}', ShoppingListShow::class)->name('show');
});
```

- The module entry screen must be `<slug>.index` — `AppNavigation::moduleItem()` links to it.
- Admin screens go under `Route::middleware('can:super-admin')->prefix('admin')`.
- Models with `HasFriendlyId` bind by `friendly_id` automatically (`getRouteKeyName()`), so `{list}` takes the public id.
- `module:` is also a Livewire persistent middleware, so component updates are gated too. Don't re-check the module inside the component.

### A brand-new module also needs
- `Module::<NAME>` const in `app/Models/Module.php` and a row in `database/seeders/LookupSeeder.php` (`modules`, with `sort_order`, `is_available_on_mobile`).
- Icon in `AppNavigation::MODULE_ICONS` (a Heroicon name) and label in `AppNavigation::moduleLabel()`.
- Then the sidebar, tab bar, PWA manifest shortcuts and home grouping pick it up on their own.

## 3. Component class

```php
/**
 * What the screen shows and does, in a few plain sentences.
 *
 * @property-read Collection<int, Thing> $things
 */
#[Layout('layouts::app-shell')]
class ThingIndex extends Component
{
    #[Url]
    public string $filter = 'active';

    public bool $showForm = false;

    public string $name = '';

    #[Locked]
    public ?string $editingId = null;   // a friendly_id, never the numeric id

    #[Computed]
    public function things(): Collection
    {
        return Thing::query()->visibleTo($this->actor())->get();
    }

    public function create(): void
    {
        $this->reset('name', 'editingId');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(CreateThing $createThing): void
    {
        $this->resetErrorBag();
        $createThing($this->actor(), ['name' => $this->name]);
        $this->reset('showForm', 'name');
        unset($this->things);
        $this->status = __('Thing saved.');
    }

    public function render(): View
    {
        return view('livewire.things.thing-index')->title(__('Things'));
    }

    private function actor(): User { /** @var User $user */ $user = Auth::user(); return $user; }
}
```

Rules:
- Layout: `layouts::app-shell` (signed in), `layouts::guest` (visitor), `layouts::chat` (full window, no shell).
- Title via `->title(__('...'))` in `render()`; it is the large title, the tab title and the scrolled top bar.
- Collections only through `#[Computed]`; clear with `unset($this->prop)` after a change.
- Record ids as `#[Locked] public string $xId` (friendly id); a record the user can't see → `ModelNotFoundException` in `mount()`.
- Time values: the user's timezone (`$user->timezone`). Repeating schedules: `public RecurrencePicker $recurrence;` (see `App\Livewire\Forms\RecurrencePicker`).
- Multi-workspace: show a workspace picker/badge only when the user has the module in more than one workspace (`showsWorkspaces()` in `ShoppingListIndex`).
- Realtime: `getListeners()` with `'echo-private:'.Event::channelFor($id).',.event.name' => 'refresh'`; must still work without Echo.

## 4. View

`resources/views/livewire/<module>/<kebab-name>.blade.php`, starting with a `{{-- --}}` comment describing the screen.

```blade
{{-- The things of the user's workspace: ... --}}
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-ui.segmented-control name="filter" wire:model.live="filter" :label="__('Show')" :value="$filter" :options="$this->filterOptions()" />
        <x-button :text="__('New thing')" icon="plus" wire:click="create" data-thing-create />
    </div>

    @if (filled($status))
        <p role="status" class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @if ($this->things->isEmpty())
        <x-ui.empty-state icon="cube" :title="__('No things yet')" :description="__('...')" />
    @else
        <x-ui.inset-grouped-list>
            @foreach ($this->things as $thing)
                <x-ui.list-row wire:key="thing-{{ $thing->friendly_id }}" data-thing="{{ $thing->friendly_id }}">...</x-ui.list-row>
            @endforeach
        </x-ui.inset-grouped-list>
    @endif

    <x-modal wire="showForm" :title="__('New thing')">
        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-thing-form>
            <x-input :label="__('Name')" id="thing-name" wire:model="name" maxlength="100" required />

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Create')" icon="check" loading="save" />
            </div>
        </form>
    </x-modal>
</div>
```

Rules:
- Build from `<x-ui.*>` (page-header, inset-grouped-list, list-row, segmented-control, empty-state, settings-section, search-field, confirm-dialog, status-badge, localized-date, money, recurrence-picker) and TallStackUI (`<x-button>`, `<x-input>`, `<x-select.native>`, `<x-toggle>`, `<x-textarea>`, `<x-modal>`) — already themed; don't restyle per call.
- Colors only from tokens (`text-label`, `text-secondary-label`, `bg-tint-fill text-on-tint`, `text-danger`…). No palette colors, hex or `rgb()` — `tests/Unit/ThemeTokensTest.php` fails.
- Never let a field's `id`/`name` be a DOM form property (`tagName`, `action`, `submit`, `name`, `id`, `method`, `target`…). TallStackUI uses the `wire:model` as the default id, so give such fields an explicit `id`.
- Destructive actions go through `<x-ui.confirm-dialog danger ...>`.
- Dates: `<x-ui.localized-date :value="$x->due_at" format="datetime" />`; money: `<x-ui.money>`.
- Add `data-<module>-<thing>` hooks on every interactive element — tests rely on them.
- Long lists with per-row actions: one shared menu (see `whatsapp-chat-menu.js` / `openMenu()` in `chats-page.blade.php`), not a dropdown per row. If the list is costly and other actions don't change it, put it in `@island(name: '...')` and re-send it with `renderIsland()` from `rendered()` only when it changed; act inside the island with `$wire.method()`.
- Alpine strings: `@js(__('...'))`.

## 5. Mobile and PWA concerns

- Check at 360px: no horizontal scroll, buttons wrap (`flex-wrap`), long names `truncate` with `min-w-0`.
- The app shell already pads `main` for the floating tab bar and safe areas; a `layouts::chat` screen must handle `env(safe-area-inset-*)` itself.
- Touch targets ≥ 44px; don't force input font sizes under 16px (iOS zoom; `app.css` overrides on `pointer: coarse`).
- Modals become bottom sheets below 640px; keep sheet forms short, Cancel + primary action right-aligned at the end of the form (`novalidate`: the Action validates).
- Grouped screens (several routes under one menu group) get `<x-ui.section-tabs>` from the shell: add the route to the group in `AppNavigation` instead of building tabs.
- Nothing extra for the PWA: pages go to the network (the service worker only caches `/build/assets`, icons and the offline page). Don't add caching for Livewire endpoints.

## 6. Translations

Every new string: `__('English sentence')`, then append the key to both `lang/en.json` (identical value) and `lang/pt_BR.json` (Portuguese). Plurals via `trans_choice('{1} ...|[2,*] ...', $n)`. Check:

```bash
python3 -c "import json,re,sys;p=json.load(open('lang/pt_BR.json'));e=json.load(open('lang/en.json'));print([k for k in e if k not in p])"
```

## 7. Tests (Pest, `tests/Feature/<Module><Screen>Test.php`)

```php
beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    $this->user = User::factory()->withModules([Module::SHOPPING])->create();
});

test('the screen needs the module', function () {
    $this->actingAs($this->user)->get(route('things.index'))->assertOk()->assertSee('data-thing-create', false);
    $this->actingAs(User::factory()->withModules([Module::REMINDERS])->create())->get(route('things.index'))->assertForbidden();
});

test('a thing is created from the sheet', function () {
    Livewire::actingAs($this->user)->test(ThingIndex::class)
        ->call('create')->assertSet('showForm', true)
        ->set('name', 'Milk')->call('save')
        ->assertHasNoErrors()->assertSee('Milk');
});
```

Cover: module gate (403 without the module), visibility (another workspace's record → 404), the happy path through the Action, validation errors shown, pt_BR rendering when copy matters (`User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR)])`), and, for screens with `<form>`s, add them to `tests/Feature/ChatFormFieldNamesTest.php`'s check (`fieldsHidingFormProperties($html)`).

Run only what you touched: `php artisan test --compact --filter=Thing`, then `vendor/bin/pint --dirty --format agent`.

## Checklist

- [ ] Action in `app/Actions/<Module>/` holds the rule; component only calls it
- [ ] Route in the module's `module:` group (or `can:super-admin`), named `<slug>.*`; entry screen is `<slug>.index`
- [ ] New module: `Module` const, `LookupSeeder` row, icon + label in `AppNavigation`
- [ ] `#[Layout('layouts::…')]` and `->title(__('…'))`
- [ ] Friendly ids, `#[Locked]`, `#[Computed]` + `unset()`
- [ ] View starts with a description comment; uses `<x-ui.*>` and token colors only
- [ ] No field id/name shadowing a form property
- [ ] `data-*` hooks on interactive elements
- [ ] Works at 360px; touch targets ≥ 44px
- [ ] Every string in `lang/en.json` and `lang/pt_BR.json`
- [ ] Feature tests: gate, visibility, happy path, validation; `ThemeTokensTest` and `AppShellNavigationTest` still pass
