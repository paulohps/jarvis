<?php

namespace App\Services\Notifications\Types;

use App\Models\ChatConversation;
use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationType;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * A conversation of the chat screen got a message from someone else, on
 * any provider but WhatsApp (whose messages keep their own push,
 * `whatsapp_message_received`, which opens the WhatsApp screen): pushed to
 * the apps only, never to the bell or by e-mail. Not
 * sent for a muted conversation. The pushes of a conversation group
 * together on the phone, in their own Android channel. The link opens the
 * conversation.
 */
final class ChatMessageReceived extends CenterNotification
{
    public const string CHAT_ROUTE = 'chats.chat';

    public const string ANDROID_CHANNEL = 'chat_messages';

    public const int EXCERPT_LENGTH = 160;

    public function __construct(
        public ChatConversation $conversation,
        public ?string $senderName,
        public string $excerpt,
    ) {}

    public static function key(): string
    {
        return NotificationType::CHAT_MESSAGE_RECEIVED;
    }

    public static function label(): string
    {
        return __('New chat message');
    }

    public static function description(): string
    {
        return __('Tells on the phone that a conversation of the chat screen (Slack…) got a message.');
    }

    public static function module(): string
    {
        return Module::CHATS;
    }

    public static function channels(): array
    {
        return [NotificationChannel::PUSH];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([
            TemplateVariable::text('chat.name', 'The person, group or channel', 'Ana'),
            TemplateVariable::text('chat.provider', 'Where it came from', 'Slack'),
            TemplateVariable::text('sender.name', 'Who sent it', 'Ana'),
            TemplateVariable::text('message.excerpt', 'The beginning of the message, or what it holds', 'Can you review the PR?'),
            TemplateVariable::text('chat.url', 'The link to the conversation', 'https://jarvis.example/chats/chat?conversation=cnv_8Kq2'),
        ]);
    }

    public static function links(): array
    {
        return [
            'chat' => NotificationLink::make(
                'Open the conversation',
                static fn (array $context, object $notifiable): ?string => $context['chat']['url'] ?? null,
            ),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        $action = [['link_key' => 'chat', 'label' => $locale === Locale::PT_BR ? 'Abrir a conversa' : 'Open the conversation']];

        return new ChannelDefaults('{% chat.name %}', '{% message.excerpt %}', $action);
    }

    public static function pushChannel(): string
    {
        return self::ANDROID_CHANNEL;
    }

    public function pushThread(): string
    {
        return 'chat:'.$this->conversation->friendly_id;
    }

    public function context(object $notifiable): array
    {
        $conversation = $this->conversation;
        $text = Str::limit(Str::squish($this->excerpt), self::EXCERPT_LENGTH);
        $sender = $this->senderName;

        return [
            'chat' => [
                'name' => $conversation->displayName(),
                'provider' => $conversation->provider->name,
                'url' => Route::has(self::CHAT_ROUTE)
                    ? route(self::CHAT_ROUTE, ['conversation' => $conversation->friendly_id])
                    : route('dashboard'),
            ],
            'sender' => ['name' => $sender ?? $conversation->displayName()],
            // In a group or a channel, who sent it comes first.
            'message' => ['excerpt' => ! $conversation->isDirect() && $sender !== null ? "{$sender}: {$text}" : $text],
        ];
    }
}
