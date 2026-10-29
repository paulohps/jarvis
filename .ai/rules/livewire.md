---
paths:
  - 'app/Livewire/**'
  - 'resources/views/livewire/**'
---

# Livewire components (Livewire 4, class-based)

## Class components, views under resources/views/livewire
Every screen is a class in `app/Livewire/<Module>/` with a Blade view in `resources/views/livewire/<module>/<kebab-name>.blade.php`. No single-file / Volt components. Routes use `Route::livewire(...)` in `routes/web.php`.
Example: `app/Livewire/Shopping/ShoppingListIndex.php`.

## Layout and title
Full-page components declare `#[Layout('layouts::app-shell')]` (signed-in), `layouts::guest` (visitor screens) or `layouts::chat` (full-window chat screens, no shell). The title is set in `render()`: `view(...)->title(__('Shopping list'))` — it becomes the large title, the tab title and the scrolled top bar text.

## Business rules live in Actions, injected into methods
A component never writes models directly for a business operation: it calls `app/Actions/<Module>/*` (the same Action the API controller calls), injected as a method parameter: `public function save(CreateShoppingList $create)`. Validation happens inside the Action; its `ValidationException` lands in the component's error bag. Call `$this->resetErrorBag()` first.

## Public ids are friendly_id strings, #[Locked]
Components keep record references as `#[Locked] public string $listId` holding `friendly_id`, never the numeric id or the model as a public property, and reload through a `#[Computed]` that re-applies visibility (`visibleTo($user)`). A record the user may not see throws `ModelNotFoundException` in `mount()` (404, not 403). See `ShoppingListShow::mount()`.

## Module gate is route middleware, persisted to updates
Module screens sit in `Route::middleware('module:'.Module::X)` groups. `SetLocale` and `EnsureModuleAccess` are Livewire persistent middleware (`AppServiceProvider::configureLivewire()`), so updates of an open screen are refused once the module is switched off — don't re-check the module inside the component.

## Computed properties, cleared by unset()
Lists and records are `#[Computed]` methods documented with `@property-read` on the class. After a mutation, `unset($this->items, $this->checkedCount)` instead of re-querying into public properties. Never store collections in public properties (payload size).

## Sheets are TallStackUI modals bound to a bool
Forms open in `<x-modal wire="showForm">` driven by a `public bool $showForm`. A `create()`/`edit()` method resets fields + validation, then sets the flag. If the dialog needs no server data, open it from Alpine (`$wire.showPoll = true`) to skip a round trip (chats inbox, `chats-page.blade.php`).

## Islands: render a heavy list only when it changed
The chat screens wrap their conversation list in `@island(name: 'conversations')` (`chats/chats-page.blade.php`, `whatsapp/chat.blade.php` uses `chats`). The rest of the screen's updates skip it; an action that changes the list sets a flag and `rendered()` calls `$this->renderIsland('conversations')` (`ChatsPage::refreshList()`). An island has its own scope: everything it uses must come from `$this` / computed properties, not view variables.

## Inside an island, act through $wire, not wire:click
A `wire:click` inside an island re-renders only the island. Rows in `chats/partials/conversation-list.blade.php` call `$wire.method()` from Alpine so the whole screen updates; the open row is marked by Alpine from `$wire`, not by re-rendering the list.

## One shared menu, not a dropdown per row
Long lists (chats, messages) must not render a `<x-dropdown>` per row: with 150 chats that took ~750 ms per render, one shared menu ~150 ms. Rows carry `data-*` attributes and open one shared menu (`$store.chatMenu` in `resources/js/components/whatsapp-chat-menu.js`, or the inbox's `openMenu()` in `chats-page.blade.php`).

## Field id/name must not shadow a form property
TallStackUI inputs default their `id` to the `wire:model` name. A field named `tagName`, `action`, `submit`, `id`… inside a `<form>` shadows that DOM property; Livewire's morph then crashes mid-update and the screen stops changing (the inbox schedule dialog never opened). Give such fields an explicit id: `<x-input id="inbox-tag-name" wire:model="tagName">`. Guarded by `tests/Feature/ChatFormFieldNamesTest.php` — extend it when adding a screen with forms.

## Realtime via echo-private listeners
Realtime screens listen with `getListeners()` returning `'echo-private:'.Event::channelFor($id).',.event.name' => 'method'` (`ShoppingListShow`, `Notifications\Bell`). Without `VITE_REVERB_APP_KEY` Echo is absent and screens must still work (no realtime).

## Skip renders that change nothing
Fire-and-forget actions (presence, read marks, a deleted list redirecting away) call `$this->skipRender()` (`ShoppingListShow::refreshList()`, `Whatsapp\Chat`). Navigation after an action uses `redirectRoute(..., navigate: true)`.

## Feedback: inline status or toast
Most screens show success as `public ?string $status` rendered in a `<p role="status">` with `text-success`; `ProfilePage` uses TallStackUI `Interactions` toasts. Errors from an external channel are caught into `$error` (see `ChatsPage::guard()`), never left as exceptions.

## Shared recurrence form
Any "repeat on a schedule" field is the `App\Livewire\Forms\RecurrencePicker` form object: `public RecurrencePicker $recurrence;`, drawn with `<x-ui.recurrence-picker :form="$recurrence" />`, loaded with `$this->recurrence->load($user->timezone)`. Its values are wall-clock in the owner's timezone; errors come back as `recurrence.*`.

## Timezone and workspace in views
Times are shown with `<x-ui.localized-date>` (user timezone), never `->format()` on UTC values in Blade. Workspace pickers/badges appear only when the user has the module in more than one workspace (`showsWorkspaces()` in Shopping/Invoices).

## data-* hooks for tests
Interactive elements carry `data-<module>-<thing>` attributes (`data-shopping-create`, `data-chat-schedule-form`). Tests assert on them (`assertSeeHtml('data-…')`) instead of on CSS classes or copy. Keep them when restyling.
