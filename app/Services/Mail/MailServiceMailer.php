<?php

namespace App\Services\Mail;

use App\Models\MailService;
use App\Models\MailServiceType;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Turns a mail service into a Laravel mailer: its configuration goes under
 * `mail.mailers.mail_service_{id}` and the cached mailer of that name is
 * dropped, so a worker that keeps running always sends with the stored
 * credentials. The service's sender becomes the mailer's "from".
 */
final class MailServiceMailer
{
    public const string NAME_PREFIX = 'mail_service_';

    /**
     * Registers the service's mailer and returns its name, for
     * `Mail::mailer()` or `MailMessage::mailer()`.
     */
    public static function register(MailService $service): string
    {
        $name = self::NAME_PREFIX.$service->id;

        config(["mail.mailers.{$name}" => self::configFor($service)]);
        Mail::purge($name);

        return $name;
    }

    /**
     * The registered mailer of the service.
     */
    public static function for(MailService $service): Mailer
    {
        return Mail::mailer(self::register($service));
    }

    /**
     * @return array<string, mixed>
     */
    public static function configFor(MailService $service): array
    {
        $from = ['address' => $service->from_address, 'name' => $service->from_name ?? config('mail.from.name')];

        return match ($service->type->slug) {
            MailServiceType::SMTP => [
                'transport' => 'smtp',
                'scheme' => $service->encryption === MailService::ENCRYPTION_SSL ? 'smtps' : 'smtp',
                'host' => $service->host,
                'port' => $service->port,
                'username' => $service->username,
                'password' => $service->secret,
                'timeout' => 30,
                'from' => $from,
            ],
            MailServiceType::RESEND => [
                'transport' => 'resend',
                'key' => $service->secret,
                'from' => $from,
            ],
            MailServiceType::GMAIL => [
                'transport' => 'gmail',
                'refresh_token' => $service->secret,
                'from' => $from,
            ],
            default => throw new \InvalidArgumentException("Unknown mail service type [{$service->type->slug}]."),
        };
    }
}
