<?php

namespace App\Services\Notifications\Types;

use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationType;
use App\Models\WhatsappAccount;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Illuminate\Support\Facades\Route;

/**
 * A connected WhatsApp number dropped (US-7.2, US-5.4): the Evolution API
 * reported `close` for an account that was `open`. Sent to the account's
 * owner only, in the app, by e-mail and by push — never through WhatsApp,
 * since the number that would send it is the one that fell. The link opens
 * the reconnection screen with a fresh QR code.
 */
final class WhatsappAccountDisconnected extends CenterNotification
{
    public const string RECONNECT_ROUTE = 'whatsapp.accounts.reconnect';

    public function __construct(public WhatsappAccount $account) {}

    public static function key(): string
    {
        return NotificationType::WHATSAPP_ACCOUNT_DISCONNECTED;
    }

    public static function label(): string
    {
        return __('WhatsApp account disconnected');
    }

    public static function description(): string
    {
        return __('Tells the owner that one of their WhatsApp numbers was disconnected.');
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
            TemplateVariable::text('account.nickname', 'The nickname of the account', 'Personal'),
            TemplateVariable::text('account.phone_number', 'The connected number', '+5511999990000'),
            TemplateVariable::text('account.url', 'The link that reconnects the account', 'https://jarvis.example/whatsapp/accounts/wacc_01/connect'),
        ]);
    }

    public static function links(): array
    {
        return [
            'reconnect' => NotificationLink::make(
                'Reconnect the account',
                static fn (array $context, object $notifiable): ?string => $context['account']['url'] ?? null,
            ),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        $wording = match ($locale) {
            Locale::PT_BR => [
                'title' => 'O WhatsApp {% account.nickname %} foi desconectado',
                'sentence' => 'O número {% account.phone_number %} ({% account.nickname %}) foi desconectado do Jarvis. As mensagens por ele não serão enviadas até você reconectar.',
                'action' => 'Reconectar',
            ],
            default => [
                'title' => 'WhatsApp {% account.nickname %} was disconnected',
                'sentence' => 'The number {% account.phone_number %} ({% account.nickname %}) was disconnected from Jarvis. Messages from it will not be sent until you reconnect it.',
                'action' => 'Reconnect',
            ],
        };

        $actions = [['link_key' => 'reconnect', 'label' => $wording['action']]];

        return $channel === NotificationChannel::PUSH
            ? new ChannelDefaults($wording['title'], $wording['sentence'], $actions)
            : new ChannelDefaults($wording['title'], "<p>{$wording['sentence']}</p>", $actions);
    }

    public function context(object $notifiable): array
    {
        return [
            'account' => [
                'nickname' => $this->account->nickname,
                'phone_number' => $this->account->phone_number ?? '',
                'url' => self::reconnectUrl($this->account),
            ],
        ];
    }

    /**
     * The reconnection screen of the account, or the WhatsApp home until it
     * exists.
     */
    public static function reconnectUrl(WhatsappAccount $account): string
    {
        return Route::has(self::RECONNECT_ROUTE) ? route(self::RECONNECT_ROUTE, $account) : route('dashboard');
    }
}
