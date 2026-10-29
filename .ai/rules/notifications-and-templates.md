---
paths:
  - 'app/Services/Notifications/**'
  - 'app/Actions/Notifications/**'
  - 'app/Models/Notification*.php'
  - 'app/Observers/NotificationSettingsObserver.php'
  - 'app/Support/NotificationSettingsCache.php'
  - 'app/Events/NotificationCreated.php'
  - 'app/Livewire/Notifications/**'
  - 'app/Livewire/Admin/Notifications/**'
  - 'app/Http/Controllers/Notifications/**'
  - 'app/Http/Controllers/Api/V1/NotificationController.php'
  - 'app/Services/Push/**'
  - 'app/Services/Templates/**'
  - 'app/Actions/Templates/**'
  - 'app/Rules/ValidTemplate.php'
  - 'app/Services/Mail/**'
  - 'app/Actions/Mail/**'
  - 'app/Models/MailService*.php'
  - 'app/Livewire/Mail/**'
  - 'app/Http/Controllers/Mail/**'
  - 'config/notifications.php'
  - 'resources/views/mail/notifications/**'
  - 'resources/views/livewire/notifications/**'
  - 'resources/views/livewire/admin/notifications/**'
  - 'resources/views/livewire/mail/**'
  - 'tests/Fixtures/Notifications/**'
  - 'tests/Feature/*Notification*'
  - 'tests/Feature/Template*'
  - 'tests/Feature/MailServicesTest.php'
---

# Notifications center, templates and mail services

## Types are classes, rows are configuration
Each type is a `CenterNotification` subclass in `app/Services/Notifications/Types/`, listed in `config/notifications.php`. Its rows (`notification_types`, `notification_type_channels`, `notification_templates`) are created by `notifications:provision` (`NotificationProvisioner`), not by `LookupSeeder`. A registered class without rows is "not provisioned" and never sent. Provisioning runs in the deploy entrypoint, `jarvis:install` and tests' `beforeEach`. Skill: `jarvis-notification-type`.

## Provisioning never overwrites edits
Existing type/channel rows keep their switches; a missing template is created; a template nobody edited follows the class's current `defaults()`; a template with `updated_by_id` (edited by the super admin) is never touched. So changing default wording in code only reaches untouched templates.

## Send only through `NotificationCenter::dispatch()`
`app(NotificationCenter::class)->dispatch(new SomeType(...), $users)` filters once (provisioned, active, user deleted/deactivated, module), then Laravel queues one job per recipient per channel on the `notifications` queue (3 tries, backoff 30/120s). Never call `$user->notify()` directly: it skips the batch preload and the module check.

## The gate runs twice
`NotificationGate` decides in `via()` and again in `shouldSend()` in the worker, so switching a type, channel or preference off also drops queued jobs. Order: recipient eligible (module, push needs FCM + a device token, WhatsApp needs a number + a sender account) → type provisioned and active → channel delivered (`ChannelDrivers`), on for the platform and the type → template in the recipient's locale or English → preference or type×channel default. It is bound scoped; memoized preferences never outlive a request/job.

## Channels
`database` (`DatabaseChannel`: stores the type, broadcasts `NotificationCreated` to `App.Models.User.{id}` for the bell), `mail` (`CenterMailMessage`), `push` (`PushChannel` via `FcmClient`, HTTP v1 without SDK; rejected tokens are removed from the device), `whatsapp` (`WhatsappChannel` → `SendWhatsAppMessage`). A channel missing from `ChannelDrivers::DRIVERS` is never delivered whatever the DB says. Push/WhatsApp get plain text derived from the rendered HTML; push deep link = first action URL.

## WhatsApp channel safety
Users send from their own account or, if none picked, the platform account chosen by the super admin (`notification_channels.whatsapp_account_id`, must belong to a super admin). The platform account only sends to a verified number (`whatsapp_notification_phone_verified_at`, 6-digit code, 10 min, 5 tries, throttled) and never to its own number. Don't weaken this: it prevents messaging strangers on the platform's behalf.

## Settings are cached as arrays
`NotificationRegistry` reads type/channel/template rows through `NotificationSettingsCache` as plain arrays (the cache refuses objects). `NotificationSettingsObserver` flushes it on model save/delete; a bulk query update must call `NotificationRegistry::flush()` itself or the change is invisible until expiry.

## Rendering is per recipient, never re-rendered
`renderFor()` renders in the recipient's locale (`preferredLocale()`, else English) and timezone; the in-app `data` stores the rendered title/body/actions. Raw values from `context()` are formatted by `TemplateContextBuilder::format()` according to the schema type; common variables (`current_month_name`, `current_year`, `current_date`, `next_month_name`) are added for everyone. A stored template that no longer parses is reported and the type's own defaults are used.

## Actions point to declared links
Super admins never type URLs: an action is `{link_key, label}` where `link_key` is one of the type's `links()` (`NotificationLink`). A link resolving to null for a recipient is dropped. Bodies go through `HtmlSanitizer` (attributes dropped, anchors unwrapped), so links in body HTML never survive.

## Template engine syntax
Only `{% %}`: `{% path.to.var %}`, `{% if x %}…{% else %}…{% endif %}`, `{% if a.b == "v" %}` / `!=`, `{% for item in items %}…{% endfor %}` (max 500 iterations). `{{ }}` is plain text. Reserved words: `if else endif for endfor in`. Values are HTML-escaped; bodies sanitized after rendering; titles and WhatsApp are plain text. The renderer never formats or translates — the context arrives formatted.

## Schemas gate what can be written
Every context declares a `TemplateSchema` (`NotificationSchema::make([...])`, `InvoiceTemplateSchema`, `WhatsappTemplateSchema`) built from `TemplateVariable::text|number|date|dateTime|money|boolean|collection` with an example. `ValidTemplate`/`TemplateValidator` refuse unknown paths and broken tags on save with line/column; `PreviewTemplate` renders schema examples in the user's locale and timezone. Comparing a variable with `values` against anything else is refused.

## Template editor saves only real changes
`SaveNotificationTemplates` validates every title/body/label, sanitizes the body and validates again (sanitizing can break a condition), writes only changed texts with `updated_by_id`, and allows a locale without text (readers fall back to English). Admin UI: `/admin/notifications/{slug}` (`NotificationTemplateEditor`).

## Preferences
Profile matrix = `NotificationRegistry::preferenceMatrix()`: active, profile-visible types × channels usable on platform and type. `UpdateNotificationPreferences` accepts only matrix cells (422 otherwise), stores explicit choices, leaves unsent cells alone. Without a choice, `notification_type_channels.is_enabled_by_default` (from `ChannelDefaults::$isEnabledByDefault`) applies.

## Mail services: SMTP, Gmail API, Resend
`MailService` (lookup `MailServiceType`: `smtp`, `gmail`, `resend`) belongs to its creator; only a super admin sets `is_global`. `secret` (SMTP password, Resend key or Google refresh token) is encrypted, `#[Hidden]`, write-only (empty input keeps it); UI shows `secret_last_four`. Gmail connects via OAuth (`gmail.send`, offline + consent) at `/mail-services/google/callback` and sends with `GmailTransport` (`Mail::extend('gmail')`); `invalid_grant` asks to reconnect (Testing-mode OAuth apps expire tokens in 7 days).

## Using a mail service at send time
`MailServiceMailer::for($service)` registers `mail.mailers.mail_service_{id}` and purges the cached mailer each time, so long-running workers use current credentials. Usability is re-checked at send: invoices need `isUsableBy($owner)` (global or own); notification e-mail needs `isUsableForNotifications()` (global, or owner still super admin). Otherwise fall back to the default `MAIL_*` mailer. When a service stops being global, `SaveMailService` resets other users' recurrences and non-super-admin notification picks.

## Tests
`$this->seed(LookupSeeder::class)` then `$this->artisan('notifications:provision')`; to isolate, set `config(['notifications.types' => [...]])` with fixtures from `tests/Fixtures/Notifications/`. Fake push with `Tests\Fixtures\Push\FakeFcm`, WhatsApp with `FakeEvolutionApi::install()`. See `NotificationCenterTest.php`, `ProvisionNotificationTypesTest.php`, `TemplateEngineTest.php`, `MailServicesTest.php`.
