---
paths:
  - 'app/Actions/Invoices/**'
  - 'app/Services/Invoices/**'
  - 'app/Models/Invoice*.php'
  - 'app/Models/Issuer.php'
  - 'app/Models/Payer.php'
  - 'app/Livewire/Invoices/**'
  - 'app/Http/Controllers/Invoices/**'
  - 'app/Jobs/SendInvoiceDelivery.php'
  - 'app/Mail/InvoiceMail.php'
  - 'resources/views/invoices/**'
  - 'resources/views/mail/invoice.blade.php'
  - 'resources/views/livewire/invoices/**'
  - 'resources/css/invoice-pdf.css'
  - 'tests/Feature/*Invoice*'
  - 'tests/Feature/IssuerTest.php'
  - 'tests/Feature/PayerTest.php'
---

# Invoices

## Web only, workspace-scoped
The module (`Module::INVOICES`) has no API v1 endpoints: routes are in `routes/web.php` under `module:invoices`. Issuers, payers, recurrences and invoices belong to a workspace fixed at creation (`BelongsToWorkspace`); payer and issuer must be of the recurrence's workspace. Every Action re-checks the module (`AuthorizesInvoicesModule`) and answers "not found" for a record of a workspace the actor does not see (`authorizeWorkspaceRecord`). There is no "household" anymore.

## Numbers are per workspace, never reused
`InvoiceNumberGenerator::next()` reserves `YYYYMM-NNN` (issue month, counter restarts at 001) per workspace via `SELECT … FOR UPDATE` on `invoice_number_sequences` (inserted with `insertOrIgnore` first). Two workspaces may both have `202610-001`; the unique is `(workspace_id, number_year_month, number_sequence)`. A cancelled invoice keeps its number. Never compute numbers elsewhere or "fill gaps".

## PDF path collides across workspaces (known issue)
`InvoicePdf::store()` writes `invoices/{number}.pdf`, but numbers are only unique per workspace, so a second workspace's `202610-001` overwrites the first one's file. Anything touching PDF storage must keep this in mind; the fix is to include the workspace (or invoice friendly id) in the path plus a data migration.

## Idempotency per period
A recurrence slot issues at most one invoice per `period_key` (`InvoiceRecurrence::periodKeyFor()`: `Y-m` for monthly, the local date otherwise), unique `(invoice_recurrence_id, period_key)`. `GenerateInvoice` locks the recurrence row, returns the existing invoice (`wasRecentlyCreated` false) and catches `UniqueConstraintViolationException` outside the transaction (PostgreSQL aborts a transaction on a unique violation). Manual runs (`GenerateAndSendNow`, one-offs) pass no period key and always issue.

## PDF rendered after the number lock
`GenerateInvoice::withPdf()` renders through Gotenberg only after the transaction commits, so a slow PDF never holds the month's sequence lock. An invoice without `pdf_path` gets it on the next run of its period or when sent (`SendInvoiceDelivery` re-renders a missing file). Don't move rendering back inside the transaction.

## PDF is always English, CSS inlined
`InvoicePdf` (spatie/laravel-pdf, `LARAVEL_PDF_DRIVER=gotenberg`, `GOTENBERG_URL`) renders `resources/views/invoices/pdf.blade.php` in English whatever the reader's locale: dates `dd/mm/yyyy`, amount `2,000.00`, currency spelled out. Styles come from `resources/css/invoice-pdf.css` via `file_get_contents` — Gotenberg cannot reach Vite, so never use `@vite` or external assets there. Previews carry a "PREVIEW" watermark and the provisional number `YYYYMM-PREVIEW`.

## PDFs live on the private disk
`InvoicePdf::disk()` is `config('filesystems.private')` (`FILESYSTEM_PRIVATE_DISK`: `local` in dev/tests, the shared `r2-private` bucket in production, because Horizon renders and web serves). Downloads only through `InvoicePdfController` (authorized). Never put PDFs on the public/media disk.

## Status flow and guards
Statuses (lookup `InvoiceStatus`): `draft` (automatic mode), `pending_review` (requires review), `sent`, `failed`, `cancelled`. `UpdatePendingInvoice` only for draft/pending_review; `ApproveInvoice` records `approved_by_id` and sends; `CancelInvoice` ("Discard") for pending or failed; `ResendInvoice` for sent/failed with the same number and stored PDF. `GuardsInvoiceStatus` re-checks on the locked row, and "being sent" (a pending delivery) blocks every change. Errors go under the `invoice` key.

## Sending goes through deliveries
`SendInvoice` creates a `pending` `InvoiceDelivery` (`triggered_by_id` null for the scheduler) and queues `SendInvoiceDelivery` on the `invoices` queue (3 tries, backoff 30/120s). Success marks delivery and invoice `sent` and notifies `invoice_sent`; the final failure marks the delivery `failed`, the invoice `failed` only if it was never sent (a failed resend keeps it `sent`), and notifies `invoice_failed`. Don't send `InvoiceMail` directly.

## Snapshots, soft deletes
Invoices copy payer/issuer fields at issue time (`InvoiceRecurrence::newInvoice()`), so editing or deleting a payer/issuer never changes an invoice. Payers, issuers and recurrences soft-delete; a payer/issuer used by a non-deleted recurrence (even paused) cannot be deleted. Deleting a recurrence also pauses its schedule. Relations to payer/issuer use `withTrashed()`.

## Date rules on the owner's calendar
`InvoiceDateCalculator` reads the run instant in the recurrence owner's timezone: a run at 01:00 UTC on Oct 1st issues Sept 30th in São Paulo. Issue rules `execution_day` / `day_of_current_month`; due rules `days_after_issue` (0–365) / `day_of_next_month` / `last_day_of_issue_month`; a day past a short month clamps to its last day. Tests: `tests/Feature/InvoiceDateCalculatorTest.php`.

## E-mail subject/body are templates
`email_subject`/`email_body` are `{% %}` templates validated with `ValidTemplate(InvoiceTemplateSchema)` and copied to each invoice. `InvoiceMail` renders them in the invoice's `locale_id` (picked per recurrence, English default), not the sender's UI language, on the sender's timezone; replies go to the issuer. `current_month_name` is read on the invoice date. Recipients: 1–10, distinct ignoring case.

## Mail service per recurrence
A recurrence/invoice may pick a `MailService` (`mail_service_id`, null = default mailer). `SendInvoiceDelivery::usableMailService()` falls back to the default mailer when the owner can no longer use it (it stopped being global). See `notifications-and-templates.md`.

## Review notifications go to the workspace
`InvoiceNotifier` notifies every active member of the invoice's workspace with the `invoices` module (super admin members included), never other workspaces. Automatic mode sends immediately; review mode notifies `invoice_pending_review` once (only when the invoice was just created).

## Testing
Use `Pdf::fake()` (Spatie) and `Mail::fake()`; seed `LookupSeeder` and run `notifications:provision` when notifications matter. Fictional data only: "Initech Corp" (payer), "Jane Doe Consulting Ltda" (issuer). See `tests/Feature/GenerateInvoiceTest.php`, `InvoiceScheduledSlotTest.php`, `InvoiceWorkspacesTest.php`.
