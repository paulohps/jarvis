<?php

namespace App\Services\Notifications\Types;

use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationType;
use App\Models\WhatsappChatMessage;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * A chat of one of the user's numbers got a message (a contact, or a member
 * of a group): pushed to the apps only, never to the bell or by e-mail, as
 * WhatsApp does. Sent when the chat notifies messages
 * (WhatsappTarget::notifiesMessages(), on by default). The pushes of a
 * chat group together on the phone, the latest replacing the one before on
 * Android, in their own Android channel. The link opens the chat.
 */
final class WhatsappMessageReceived extends CenterNotification
{
    public const string CHAT_ROUTE = 'whatsapp.chat';

    /**
     * The Android notification channel the app creates for these pushes.
     */
    public const string ANDROID_CHANNEL = 'whatsapp_messages';

    /**
     * How much of the message the push quotes.
     */
    public const int EXCERPT_LENGTH = 160;

    public function __construct(public WhatsappChatMessage $message) {}

    public static function key(): string
    {
        return NotificationType::WHATSAPP_MESSAGE_RECEIVED;
    }

    public static function label(): string
    {
        return __('New WhatsApp message');
    }

    public static function description(): string
    {
        return __('Tells on the phone that a chat of one of your numbers got a message.');
    }

    public static function module(): string
    {
        return Module::WHATSAPP;
    }

    public static function channels(): array
    {
        return [NotificationChannel::PUSH];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([
            TemplateVariable::text('chat.name', 'The contact or group', 'Ana'),
            TemplateVariable::text('sender.name', 'Who sent it: the contact, or the member of the group', 'Ana'),
            TemplateVariable::text('message.excerpt', 'The beginning of the message, or what it holds (a photo, an audio…)', 'Are we still on for tomorrow?'),
            TemplateVariable::text('account.nickname', 'The nickname of the number that got it', 'Personal'),
            TemplateVariable::text('chat.url', 'The link to the chat', 'https://jarvis.example/whatsapp/chat?chat=wtgt_8Kq2'),
        ]);
    }

    public static function links(): array
    {
        return [
            'chat' => NotificationLink::make(
                'Open the chat',
                static fn (array $context, object $notifiable): ?string => $context['chat']['url'] ?? null,
            ),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        $action = [['link_key' => 'chat', 'label' => $locale === Locale::PT_BR ? 'Abrir a conversa' : 'Open the chat']];

        return new ChannelDefaults('{% chat.name %}', '{% message.excerpt %}', $action);
    }

    public static function pushChannel(): string
    {
        return self::ANDROID_CHANNEL;
    }

    public function pushThread(): string
    {
        return 'whatsapp:'.$this->message->target->friendly_id;
    }

    public function context(object $notifiable): array
    {
        $message = $this->message;
        $chat = $message->target;
        $sender = $message->senderLabel();
        $text = filled($message->body) ? Str::limit(Str::squish((string) $message->body), self::EXCERPT_LENGTH) : $this->placeholder($message);

        return [
            'chat' => [
                'name' => $chat->displayName(),
                'url' => Route::has(self::CHAT_ROUTE)
                    ? route(self::CHAT_ROUTE, ['account' => $chat->account->friendly_id, 'chat' => $chat->friendly_id])
                    : route('dashboard'),
            ],
            'sender' => ['name' => $sender ?? $chat->displayName()],
            // In a group, who sent it comes first, as WhatsApp shows it.
            'message' => ['excerpt' => $chat->isGroup() && $sender !== null ? "{$sender}: {$text}" : $text],
            'account' => ['nickname' => $chat->account->nickname],
        ];
    }

    private function placeholder(WhatsappChatMessage $message): string
    {
        $emoji = match ($message->summary()['icon']) {
            'photo' => '📷 ',
            'video-camera' => '🎥 ',
            'microphone' => '🎤 ',
            'document' => '📄 ',
            'map-pin' => '📍 ',
            'user' => '👤 ',
            'chart-bar' => '📊 ',
            default => '',
        };

        return $emoji.$message->summary()['label'];
    }
}
