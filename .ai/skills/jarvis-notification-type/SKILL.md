---
name: jarvis-notification-type
description: Use when adding a new notification the center sends (in-app, e-mail, push, WhatsApp) — the type class, its variables and default en/pt_BR templates, channels, provisioning, preferences and tests.
---

# Adding a notification type

A notification type is a PHP class (what it is, where it may go, what it may say, its default wording) plus rows the provisioner writes (how the super admin configured it). Read `.ai/rules/notifications-and-templates.md` first. Reference implementation: `app/Services/Notifications/Types/ReminderDue.php`; module-wide base with shared wording: `Types/InvoiceNotification.php`.

## 1. Pick the key and the module

- Add a slug constant to `app/Models/NotificationType.php` (`public const string SHOPPING_LIST_DUE = 'shopping_list_due';`). The key is snake_case and becomes `notification_types.slug`, the in-app `data.key`, the preference key and the admin URL (`/admin/notifications/{slug}`). Never rename it after release: edited templates and user preferences hang on it.
- `module()` returns a `Module::*` slug (only members with that module receive it) or `null` for an account-level type (invitation-like; the only kind that may go to an on-demand `Notification::route('mail', …)` address).

## 2. Write the class

Create `app/Services/Notifications/Types/<Name>.php`, `final class <Name> extends CenterNotification`, constructor taking the models it is about (they are serialized: `SerializesModels`, `#[DeleteWhenMissingModels]` drops the job if they disappear).

Implement every abstract method:

- `key()` → the constant; `label()` / `description()` → `__('…')` English source strings.
- `channels()` → subset of `NotificationChannel::DATABASE|MAIL|PUSH|WHATSAPP`, in display order. Provisioning creates one `notification_type_channels` row per entry.
- `schema()` → `NotificationSchema::make([TemplateVariable::text('list.name', 'The name of the list', 'Groceries'), TemplateVariable::dateTime('list.due_at', 'When it is due', '2026-10-10 09:00'), …])`. Types: `text`, `number`, `date`, `dateTime`, `money`, `boolean`, `collection`. Every variable needs a realistic example (the editor preview renders it). Only declared paths can be written in templates.
- `links()` → `['list' => NotificationLink::make('Open the list', fn (array $context, object $notifiable): ?string => $context['list']['url'] ?? null)]`. Return null when the link does not apply to that recipient; the action is then dropped.
- `defaults(string $channel, string $locale)` → `ChannelDefaults($title, $body, $actions, $isEnabledByDefault)`:
  - `match ($locale) { Locale::PT_BR => [...], default => [...] }` — English is the default and the fallback;
  - database/mail bodies are light HTML (`<p>`, lists; no links or attributes — they are sanitized away); push/WhatsApp bodies are plain text (append the URL variable for WhatsApp, which shows actions as "label: URL" anyway);
  - actions are `[['link_key' => 'list', 'label' => '…']]`;
  - set `$isEnabledByDefault` false for channels users should opt into (e.g. mail for `reminder_due`).
- `context(object $notifiable)` → raw values keyed like the schema (`['list' => ['name' => …, 'due_at' => $carbon, 'url' => route(…)]]`). Do not format dates or money here: `TemplateContextBuilder::format()` writes them out per recipient locale/timezone. Guard routes with `Route::has()` if the route is module-gated.

Optional overrides: `pushThread()` (group pushes per thread, e.g. `'chat:'.$conversation->friendly_id`) and `static pushChannel()` (an Android notification channel the apps created; adding a new one needs the Android app change in `apps/android/.../push/NotificationPresenter.kt`).

## 3. Register it

Append the class to `types` in `config/notifications.php`. Without it the registry ignores the class; without provisioning it is "not provisioned" and never sent.

## 4. Translations

Add to both `lang/en.json` and `lang/pt_BR.json`: `label()`, `description()`, every `TemplateVariable` description, every `NotificationLink` label. Template wording itself is not translated through JSON — it lives in `defaults()` per locale (`Localization::SUPPORTED_LOCALES` = `en`, `pt_BR`).

## 5. Send it

From the Action, job or `runScheduledSlot()` that owns the event:

```php
app(NotificationCenter::class)->dispatch(new ShoppingListDue($list), $recipients);
```

- `$recipients` is a `User`, an iterable of users, or an `AnonymousNotifiable` (account types only).
- Choose the audience explicitly (e.g. `InvoiceNotifier::audience()`: active members of the record's workspace with the module). Never fan out across workspaces.
- If the trigger can run twice (scheduler slot, retried job), claim before sending — a conditional update such as `ReminderOccurrence::whereNull('notified_at')->update([...]) === 1` — so it is notified once.
- Wrap in try/report when a notification failure must not fail the main work (see `SendInvoiceDelivery::handle()`).

## 6. Provision

```bash
vendor/bin/sail artisan notifications:provision
```

Idempotent: creates the type (active, visible in profile), its channels and templates in `en` and `pt_BR`; updates templates nobody edited; never touches templates with `updated_by_id`. It runs automatically on deploy (`.deploy/entrypoint.sh`, web container) and in `jarvis:install` — no migration or seeder change is needed. Don't add the type to `LookupSeeder`.

## 7. Preferences and admin

Nothing to build: the profile matrix (`NotificationRegistry::preferenceMatrix()`) and `/admin/notifications` pick the new type up from the rows. Check that the defaults per channel (`isEnabledByDefault`) are what users who never chose should get.

## 8. Tests (`tests/Feature/<Name>NotificationTest.php`)

```php
beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->artisan('notifications:provision')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:00', 'UTC'));
});
```

Cover at least:

- registered and provisioned: `app(NotificationRegistry::class)->classFor(NotificationType::X)`, one channel row per `channels()`, `is_enabled_by_default` per channel, a template per channel × `Localization::SUPPORTED_LOCALES` containing the expected variables (pattern: `tests/Feature/ReminderScheduledSlotTest.php`, `InvoiceNotificationTypesTest.php`);
- rendering in pt_BR and in the recipient's timezone (`$user->notifications()->sole()->data['title']`);
- audience: users without the module, deactivated users and other workspaces get nothing;
- sent once when the trigger repeats;
- push with `Tests\Fixtures\Push\FakeFcm`, WhatsApp with `Tests\Fixtures\Whatsapp\FakeEvolutionApi::install()` when those channels are declared.

Run: `vendor/bin/sail artisan test --compact --filter=<Name>`, then `vendor/bin/pint --dirty` and `vendor/bin/phpstan analyse`.

## Checklist

- [ ] `NotificationType::<CONST>` slug added
- [ ] Class in `app/Services/Notifications/Types/` implementing all abstract methods
- [ ] Schema variables with examples; links return null when not applicable
- [ ] `defaults()` for every channel × `en`/`pt_BR`; HTML only on database/mail; sensible `isEnabledByDefault`
- [ ] `context()` returns raw values matching schema paths
- [ ] Listed in `config/notifications.php`
- [ ] `lang/en.json` + `lang/pt_BR.json` entries for label, description, variable descriptions, link labels
- [ ] Dispatched only via `NotificationCenter::dispatch()`, audience scoped to the workspace/module, sent once per trigger
- [ ] `notifications:provision` run locally; tests provision in `beforeEach`
- [ ] Tests for provisioning, locales, audience, idempotency; Pint + Larastan clean
- [ ] Android push channel added in the app if `pushChannel()` is overridden
