<?php

namespace App\Services\Notifications\Types;

use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationType;
use App\Models\WhatsappDelivery;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * A WhatsApp message could not be sent (US-7.4, US-5.4): the account was not
 * connected, or every attempt failed. Sent to the owner of the account it
 * went from, in the app, by e-mail and by push — never through WhatsApp,
 * since sending it is what failed. The link opens the delivery history.
 */
final class WhatsappMessageFailed extends CenterNotification
{
    public const string HISTORY_ROUTE = 'whatsapp.history.index';

    /**
     * How much of the message the notification quotes.
     */
    public const int EXCERPT_LENGTH = 80;

    public function __construct(public WhatsappDelivery $delivery) {}

    public static function key(): string
    {
        return NotificationType::WHATSAPP_MESSAGE_FAILED;
    }

    public static function label(): string
    {
        return __('WhatsApp message failed');
    }

    public static function description(): string
    {
        return __('Tells that a WhatsApp message from one of your numbers could not be sent.');
    }

    public static function module(): string
    {
        return Module::WHATSAPP;
    }

    public static function channels(): array
    {
        return [NotificationChannel::DATABASE, NotificationChannel::MAIL, NotificationChannel::PUSH];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([
            TemplateVariable::text('account.nickname', 'The nickname of the account it was sent from', 'Personal'),
            TemplateVariable::text('target.name', 'The contact or group it was sent to', 'Ana'),
            TemplateVariable::text('message.excerpt', 'The beginning of the message', 'Hi Ana, the meeting is at 10.'),
            TemplateVariable::text('error', 'Why it failed', 'The WhatsApp account Personal is not connected.'),
            TemplateVariable::text('history.url', 'The link to the delivery history', 'https://jarvis.example/whatsapp/history'),
        ]);
    }

    public static function links(): array
    {
        return [
            'history' => NotificationLink::make(
                'Open the history',
                static fn (array $context, object $notifiable): ?string => $context['history']['url'] ?? null,
            ),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        $wording = match ($locale) {
            Locale::PT_BR => [
                'title' => 'Falha ao enviar WhatsApp para {% target.name %}',
                'sentence' => 'A mensagem “{% message.excerpt %}” pelo número {% account.nickname %} não foi enviada para {% target.name %}. Motivo: {% error %}',
                'action' => 'Ver o histórico',
            ],
            default => [
                'title' => 'WhatsApp to {% target.name %} could not be sent',
                'sentence' => 'The message “{% message.excerpt %}” from {% account.nickname %} was not sent to {% target.name %}. Reason: {% error %}',
                'action' => 'Open the history',
            ],
        };

        $actions = [['link_key' => 'history', 'label' => $wording['action']]];

        return $channel === NotificationChannel::PUSH
            ? new ChannelDefaults($wording['title'], $wording['sentence'], $actions)
            : new ChannelDefaults($wording['title'], "<p>{$wording['sentence']}</p>", $actions);
    }

    public function context(object $notifiable): array
    {
        $delivery = $this->delivery;

        return [
            'account' => ['nickname' => $delivery->account->nickname],
            'target' => ['name' => $delivery->target->displayName()],
            'message' => ['excerpt' => Str::limit(Str::squish($delivery->body), self::EXCERPT_LENGTH)],
            'error' => (string) $delivery->error_message,
            'history' => ['url' => Route::has(self::HISTORY_ROUTE) ? route(self::HISTORY_ROUTE) : route('dashboard')],
        ];
    }
}
