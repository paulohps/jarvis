<?php

namespace App\Services\Notifications\Types;

use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationType;
use App\Models\ReminderOccurrence;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Illuminate\Support\Facades\Route;

/**
 * A reminder is due (US-9.2, US-5.4): sent to its creator once per
 * occurrence by `Reminder::runScheduledSlot()`, with the title, the note and
 * the date and time in the creator's timezone. In the app, by push and on
 * WhatsApp by default; by e-mail only for whoever switches it on.
 */
final class ReminderDue extends CenterNotification
{
    public const string REMINDERS_ROUTE = 'reminders.index';

    public function __construct(public ReminderOccurrence $occurrence) {}

    public static function key(): string
    {
        return NotificationType::REMINDER_DUE;
    }

    public static function label(): string
    {
        return __('Reminder due');
    }

    public static function description(): string
    {
        return __('Tells you that one of your reminders is due.');
    }

    public static function module(): string
    {
        return Module::REMINDERS;
    }

    public static function channels(): array
    {
        return [
            NotificationChannel::DATABASE,
            NotificationChannel::MAIL,
            NotificationChannel::PUSH,
            NotificationChannel::WHATSAPP,
        ];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([
            TemplateVariable::text('reminder.title', 'The title of the reminder', 'Take the medicine'),
            TemplateVariable::text('reminder.note', 'The note of the reminder, if any', 'The blue box, after lunch.'),
            TemplateVariable::dateTime('reminder.due_at', 'When it is due', '2026-10-10 09:00'),
            TemplateVariable::text('reminders.url', 'The link to the reminders', 'https://jarvis.example/reminders'),
        ]);
    }

    public static function links(): array
    {
        return [
            'reminders' => NotificationLink::make(
                'Open the reminders',
                static fn (array $context, object $notifiable): ?string => $context['reminders']['url'] ?? null,
            ),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        $wording = match ($locale) {
            Locale::PT_BR => [
                'title' => 'Lembrete: {% reminder.title %}',
                'sentence' => '“{% reminder.title %}” vence em {% reminder.due_at %}.',
                'action' => 'Ver os lembretes',
            ],
            default => [
                'title' => 'Reminder: {% reminder.title %}',
                'sentence' => '“{% reminder.title %}” is due on {% reminder.due_at %}.',
                'action' => 'Open the reminders',
            ],
        };

        $actions = [['link_key' => 'reminders', 'label' => $wording['action']]];
        $note = '{% if reminder.note %} {% reminder.note %}{% endif %}';
        $isEnabledByDefault = $channel !== NotificationChannel::MAIL;

        return match ($channel) {
            NotificationChannel::PUSH => new ChannelDefaults($wording['title'], $wording['sentence'].$note, $actions),
            NotificationChannel::WHATSAPP => new ChannelDefaults($wording['title'], $wording['sentence'].$note.' {% reminders.url %}'),
            default => new ChannelDefaults(
                $wording['title'],
                "<p>{$wording['sentence']}</p>{% if reminder.note %}<p>{% reminder.note %}</p>{% endif %}",
                $actions,
                $isEnabledByDefault,
            ),
        };
    }

    public function context(object $notifiable): array
    {
        $reminder = $this->occurrence->reminder;

        return [
            'reminder' => [
                'title' => $reminder->title,
                'note' => (string) $reminder->note,
                'due_at' => $this->occurrence->due_at,
            ],
            'reminders' => ['url' => Route::has(self::REMINDERS_ROUTE) ? route(self::REMINDERS_ROUTE) : route('dashboard')],
        ];
    }
}
