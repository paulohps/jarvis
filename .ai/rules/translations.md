---
paths:
  - 'lang/**'
  - 'app/Support/Localization.php'
  - 'app/Http/Middleware/SetLocale.php'
---

# Translations

## Two languages: en (default) and pt_BR
`App\Support\Localization` is the single source: `SUPPORTED_LOCALES = ['en', 'pt_BR']`, `DEFAULT_LOCALE = 'en'`, `NAMES` for the switch. A new language needs that list, a row in the `locales` lookup (`LookupSeeder`), `lang/<code>.json` and `lang/<code>/*.php`.

## Every visible string goes through __() with English keys
Write `__('Shopping list')`, `__(':name was deleted.', ['name' => $x])` in PHP, Blade and Livewire. Keys are the English sentence itself (JSON translations), never `module.key` style. Strings shown by Alpine are passed from Blade with `@js(__('...'))`.

## Add each new key to BOTH JSON files
`lang/en.json` maps key → identical English text; `lang/pt_BR.json` maps key → Portuguese. New keys are appended at the end of both files (they are not sorted). `pt_BR.json` must have every key used by a screen; a missing one silently shows English. `pt_BR.json` has some keys `en.json` lacks (English falls back to the key) — when touching a key, add it to `en.json` too.

## Pluralization uses Laravel's pipe syntax
`trans_choice('{1} Forward scheduled to 1 conversation.|[2,*] Forward scheduled to :count conversations.', $n)` or `':count member|:count members'`. The whole piped string is the key, translated as a whole in `pt_BR.json`.

## PHP files only for framework strings
`lang/{en,pt_BR}/auth.php`, `pagination.php`, `passwords.php`, `validation.php` hold Laravel's own messages. App strings never go in PHP files.

## How the locale is picked
`SetLocale` (web + api groups, and Livewire persistent middleware): the signed-in user's `locale` lookup (`User::preferredLocale()`), else the guest's session pick (`/locale/{locale}`, `SwitchLocaleController`, guest layout switch), else `Accept-Language` on the API (`pt`, `pt-PT` → `pt_BR`), else `en`. Responses vary on `Accept-Language`. Covered by `tests/Feature/LocalizationTest.php`.

## Dates and numbers follow the locale
Carbon follows the app locale: use `isoFormat()`/`translatedFormat()` with `->settings(['locale' => ...])` when needed, or `<x-ui.localized-date>` / `<x-ui.money>` in views. Don't format dates with hard-coded English patterns like `M d`.

## Text sent to other people uses their language
Notifications, invoice e-mails and queued work render in the recipient's locale (invoice e-mails pick their language per invoice), not the request's. Pattern: set `App::setLocale($locale)` around the render and restore the previous one in `finally` (`app/Mail/InvoiceMail.php`, `PreviewTemplate`), or pass `$user->preferredLocale()` to `__()` (`CenterNotification`). Never rely on the request locale in jobs.

## Names that are not translated
Proper names a user typed or a provider owns (mail service names, account/workspace names, contact names) are shown as-is, never passed to `__()` — a name that happens to match a key would get translated. Module names go through `AppNavigation::moduleLabel()`, not the DB `name`.

## Tone
English copy is short, plain and user-facing ("Ask the admin to enable a module"); pt_BR is Brazilian Portuguese, informal "você". Keep `:placeholders` identical in the translation.
