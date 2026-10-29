<?php

namespace App\Actions\Invoices;

use App\Models\Currency;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceDueDateRule;
use App\Models\Issuer;
use App\Models\Locale;
use App\Models\MailService;
use App\Models\Payer;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ValidTemplate;
use App\Services\Invoices\InvoiceTemplateSchema;
use App\Support\Localization;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * The fields an invoice is issued from, shared by an invoice recurrence
 * (SaveInvoiceRecurrence) and a one-off invoice (CreateOneOffInvoice):
 *
 * - `payer` and `issuer` are the friendly ids of records not deleted of
 *   the workspace the invoices are issued in — a payer or issuer of
 *   another workspace is refused as invalid;
 * - `recipients`: 1 to 10 valid e-mail addresses, without repeats (case
 *   insensitive);
 * - `email_subject` and `email_body` are templates of InvoiceTemplateSchema;
 * - `service_description` is required (multiline);
 * - `currency` is the ISO code of an active currency and `amount` is above
 *   zero with at most 2 decimals;
 * - `invoice_date_rule` (`execution_day`, or `day_of_current_month` with
 *   `invoice_date_day` 1–31) and `invoice_due_date_rule` (`days_after_issue`
 *   with `due_date_value` 0–365, `day_of_next_month` with 1–31, or
 *   `last_day_of_issue_month`); the value of a rule that needs none is
 *   cleared;
 * - `delivery_mode`: `automatic` or `requires_review`;
 * - `mail_service` (optional): the friendly id of a mail service the actor
 *   may send through (their own or a global one), or the one already
 *   picked; empty for the default mailer;
 * - `locale` (optional): the language the e-mail is written in (`en` or
 *   `pt_BR`), whatever the sender's own; English when empty.
 */
final class InvoiceFieldRules
{
    public const int MAX_RECIPIENTS = 10;

    /**
     * Trims the text fields and the recipients.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $input = InvoiceInput::trim($input, ['name', 'payer', 'issuer', 'email_subject', 'email_body', 'service_description', 'currency', 'amount', 'locale']);

        if (is_array($input['recipients'] ?? null)) {
            $input['recipients'] = array_values(array_map(
                static fn (mixed $email): mixed => is_string($email) ? trim($email) : $email,
                $input['recipients'],
            ));
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function rules(array $input, Workspace $workspace, ?User $actor = null, ?int $currentMailServiceId = null): array
    {
        $dateRule = $input['invoice_date_rule'] ?? null;
        $schema = new InvoiceTemplateSchema;

        return [
            'payer' => ['required', 'string', Rule::exists('payers', 'friendly_id')->withoutTrashed()->where('workspace_id', $workspace->id)],
            'issuer' => ['required', 'string', Rule::exists('issuers', 'friendly_id')->withoutTrashed()->where('workspace_id', $workspace->id)],
            'recipients' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'string', 'email', 'max:255', 'distinct:ignore_case'],
            'email_subject' => ['required', 'string', 'max:512', new ValidTemplate($schema)],
            'email_body' => ['required', 'string', 'max:20000', new ValidTemplate($schema)],
            'service_description' => ['required', 'string', 'max:5000'],
            'currency' => ['required', 'string', Rule::exists('currencies', 'slug')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'invoice_date_rule' => ['required', 'string', Rule::in([InvoiceDateRule::EXECUTION_DAY, InvoiceDateRule::DAY_OF_CURRENT_MONTH])],
            'invoice_date_day' => [
                $dateRule === InvoiceDateRule::DAY_OF_CURRENT_MONTH ? 'required' : 'nullable',
                'integer', 'between:1,31',
            ],
            'invoice_due_date_rule' => ['required', 'string', Rule::in([
                InvoiceDueDateRule::DAYS_AFTER_ISSUE, InvoiceDueDateRule::DAY_OF_NEXT_MONTH, InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH,
            ])],
            'due_date_value' => match ($input['invoice_due_date_rule'] ?? null) {
                InvoiceDueDateRule::DAYS_AFTER_ISSUE => ['required', 'integer', 'between:0,365'],
                InvoiceDueDateRule::DAY_OF_NEXT_MONTH => ['required', 'integer', 'between:1,31'],
                default => ['nullable'],
            },
            'delivery_mode' => ['required', 'string', Rule::in([InvoiceDeliveryMode::AUTOMATIC, InvoiceDeliveryMode::REQUIRES_REVIEW])],
            'mail_service' => ['nullable', 'string', Rule::exists('mail_services', 'friendly_id')->where(
                static function (Builder $query) use ($actor, $currentMailServiceId): void {
                    $query->where('is_global', true)
                        ->when($actor !== null, fn (Builder $query) => $query->orWhere('user_id', $actor?->id))
                        ->when($currentMailServiceId !== null, fn (Builder $query) => $query->orWhere('id', $currentMailServiceId));
                },
            )],
            'locale' => ['nullable', 'string', Rule::in(Localization::SUPPORTED_LOCALES)],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function attributes(array $input): array
    {
        return [
            'payer' => __('payer'),
            'issuer' => __('issuer'),
            'recipients' => __('recipients'),
            'recipients.*' => __('recipient'),
            'email_subject' => __('subject'),
            'email_body' => __('body'),
            'service_description' => __('description'),
            'currency' => __('currency'),
            'amount' => __('amount'),
            'invoice_date_rule' => __('invoice date rule'),
            'invoice_date_day' => __('day of the month'),
            'invoice_due_date_rule' => __('due date rule'),
            'due_date_value' => ($input['invoice_due_date_rule'] ?? null) === InvoiceDueDateRule::DAYS_AFTER_ISSUE ? __('days after the invoice date') : __('day of the month'),
            'delivery_mode' => __('sending'),
            'mail_service' => __('mail service'),
            'locale' => __('language'),
        ];
    }

    /**
     * The validated fields as `invoice_recurrences` columns (without the
     * recipients).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function columns(array $validated): array
    {
        $usesDateDay = $validated['invoice_date_rule'] === InvoiceDateRule::DAY_OF_CURRENT_MONTH;
        $usesDueValue = $validated['invoice_due_date_rule'] !== InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH;

        return [
            'payer_id' => Payer::query()->where('friendly_id', $validated['payer'])->value('id'),
            'issuer_id' => Issuer::query()->where('friendly_id', $validated['issuer'])->value('id'),
            'currency_id' => Currency::idFor($validated['currency']),
            'amount' => number_format((float) $validated['amount'], 2, '.', ''),
            'service_description' => $validated['service_description'],
            'email_subject' => $validated['email_subject'],
            'email_body' => $validated['email_body'],
            'invoice_date_rule_id' => InvoiceDateRule::idFor($validated['invoice_date_rule']),
            'invoice_date_day' => $usesDateDay ? (int) ($validated['invoice_date_day'] ?? null) : null,
            'invoice_due_date_rule_id' => InvoiceDueDateRule::idFor($validated['invoice_due_date_rule']),
            'due_date_value' => $usesDueValue ? (int) ($validated['due_date_value'] ?? null) : null,
            'invoice_delivery_mode_id' => InvoiceDeliveryMode::idFor($validated['delivery_mode']),
            'mail_service_id' => filled($validated['mail_service'] ?? null)
                ? MailService::query()->where('friendly_id', $validated['mail_service'])->value('id')
                : null,
            'locale_id' => Locale::idFor(filled($validated['locale'] ?? null) ? $validated['locale'] : Localization::DEFAULT_LOCALE),
        ];
    }
}
