<?php

namespace App\Actions\Mail;

use App\Models\InvoiceRecurrence;
use App\Models\MailService;
use App\Models\MailServiceType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a mail service: an SMTP server (host, port, encryption,
 * user and password) or Resend (API key), with its name and sender. A
 * Google account is added by connecting it (`ConnectGmailMailService`);
 * here it only changes its name, sender name and whether it is global.
 *
 * The password or key is encrypted at rest and write-only: editing without
 * a new one keeps the stored one. Only the super admin marks a service
 * global. When one stops being global, other users' invoice recurrences
 * that picked it go back to the default mailer, and so do the notification
 * types, unless its owner is a super admin (whose services the notification
 * e-mail may use either way).
 */
final readonly class SaveMailService
{
    /**
     * @param  array{type?: mixed, name?: mixed, from_address?: mixed, from_name?: mixed, host?: mixed, port?: mixed, encryption?: mixed, username?: mixed, secret?: mixed, is_global?: mixed}  $input  `type` is the slug
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input, ?MailService $service = null): MailService
    {
        if ($service !== null) {
            Gate::forUser($actor)->authorize('update', $service);
        }

        foreach (['name', 'from_address', 'from_name', 'host', 'username', 'secret'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }

        $type = $service?->type->slug ?? (is_string($input['type'] ?? null) ? $input['type'] : null);
        $isSmtp = $type === MailServiceType::SMTP;
        $isGmail = $type === MailServiceType::GMAIL;

        /** @var array{type: string, name: string, from_address?: string, from_name?: string|null, host?: string, port?: int, encryption?: string|null, username?: string|null, secret?: string|null, is_global?: bool} $validated */
        $validated = Validator::make([...$input, 'type' => $type], [
            'type' => [
                'required', 'string',
                Rule::exists('mail_service_types', 'slug')->where('is_active', true),
                // A Google account comes only from connecting it.
                Rule::notIn($service === null ? [MailServiceType::GMAIL] : []),
            ],
            'name' => ['required', 'string', 'max:100'],
            'from_address' => $isGmail ? ['exclude'] : ['required', 'string', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'host' => $isSmtp ? ['required', 'string', 'max:255'] : ['exclude'],
            'port' => $isSmtp ? ['required', 'integer', 'between:1,65535'] : ['exclude'],
            'encryption' => $isSmtp ? ['nullable', 'string', Rule::in([MailService::ENCRYPTION_TLS, MailService::ENCRYPTION_SSL])] : ['exclude'],
            'username' => $isSmtp ? ['nullable', 'string', 'max:255'] : ['exclude'],
            'secret' => $isGmail ? ['exclude'] : [
                // SMTP may need no password; editing keeps the stored one.
                $service === null && ! $isSmtp ? 'required' : 'nullable',
                'string', 'min:4', 'max:2000',
            ],
            'is_global' => $actor->isSuperAdmin() ? ['sometimes', 'boolean'] : ['exclude'],
        ], attributes: [
            'from_address' => __('sender address'),
            'from_name' => __('sender name'),
            'secret' => $type === MailServiceType::RESEND ? __('API key') : __('password'),
        ])->validate();

        return DB::transaction(function () use ($actor, $service, $validated, $isSmtp): MailService {
            $service ??= new MailService([
                'mail_service_type_id' => MailServiceType::idFor($validated['type']),
                'user_id' => $actor->id,
                'is_global' => false,
            ]);

            $wasGlobal = $service->exists && $service->is_global;

            $service->fill([
                'name' => $validated['name'],
                'from_name' => filled($validated['from_name'] ?? null) ? $validated['from_name'] : null,
            ]);

            if (array_key_exists('from_address', $validated)) {
                $service->from_address = $validated['from_address'];
            }

            if ($isSmtp) {
                $service->fill([
                    'host' => $validated['host'] ?? null,
                    'port' => $validated['port'] ?? null,
                    'encryption' => $validated['encryption'] ?? null,
                    'username' => filled($validated['username'] ?? null) ? $validated['username'] : null,
                ]);
            }

            if (filled($validated['secret'] ?? null)) {
                $service->fill([
                    'secret' => $validated['secret'],
                    'secret_last_four' => substr($validated['secret'], -4),
                ]);
            }

            if (array_key_exists('is_global', $validated)) {
                $service->is_global = $validated['is_global'];
            }

            $service->save();

            if ($wasGlobal && ! $service->is_global) {
                self::releaseFromOthersRecurrences($service);

                if (! $service->isUsableForNotifications()) {
                    self::releaseFromNotifications($service);
                }
            }

            return $service;
        });
    }

    /**
     * Whatever picked the service, besides its owner's invoice recurrences,
     * goes back to the default mailer.
     */
    public static function releaseFromOthers(MailService $service): void
    {
        self::releaseFromNotifications($service);
        self::releaseFromOthersRecurrences($service);
    }

    /**
     * The notification types that send through the service go back to the
     * default mailer. Through the models, so the cached notification
     * settings are dropped.
     */
    public static function releaseFromNotifications(MailService $service): void
    {
        NotificationTypeChannel::query()
            ->where('mail_service_id', $service->id)
            ->get()
            ->each(fn (NotificationTypeChannel $typeChannel): bool => $typeChannel->update(['mail_service_id' => null]));
    }

    /**
     * Other users' invoice recurrences that send through the service go
     * back to the default mailer; its owner's keep it.
     */
    public static function releaseFromOthersRecurrences(MailService $service): void
    {
        InvoiceRecurrence::withTrashed()
            ->where('mail_service_id', $service->id)
            ->where('created_by_id', '!=', $service->user_id)
            ->update(['mail_service_id' => null]);
    }
}
