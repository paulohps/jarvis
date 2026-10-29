<?php

namespace App\Services\Notifications\Types;

use App\Models\Invitation;
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
use App\Support\Navigation\AppNavigation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * The invitation e-mail (US-1.1, US-5.4): someone without an account is
 * invited to a workspace, named in the text, with the link that accepts it.
 * E-mail only, to the invited address (an on-demand notifiable, see
 * `recipientFor()`), in the language chosen in the invitation.
 *
 * The accept link carries the plain token, which is never stored, so whoever
 * sends it passes the URL in.
 */
final class InvitationReceived extends CenterNotification
{
    public function __construct(
        public Invitation $invitation,
        public string $acceptUrl,
    ) {
        $this->locale = $invitation->locale->slug;
    }

    /**
     * The invited address, as a recipient without an account.
     */
    public static function recipientFor(Invitation $invitation): AnonymousNotifiable
    {
        return Notification::route('mail', $invitation->email);
    }

    public static function key(): string
    {
        return NotificationType::INVITATION;
    }

    public static function label(): string
    {
        return __('Invitation');
    }

    public static function description(): string
    {
        return __('Invites someone without an account to join Jarvis, with the link that accepts it.');
    }

    public static function module(): ?string
    {
        return null;
    }

    public static function channels(): array
    {
        return [NotificationChannel::MAIL];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([
            TemplateVariable::text('inviter.name', 'Name of who sent the invitation', 'Paulo'),
            TemplateVariable::text('invitation.email', 'The invited e-mail address', 'ana@example.com'),
            TemplateVariable::text('workspace.name', 'Name of the workspace the invited person joins', 'Silva family'),
            TemplateVariable::dateTime('invitation.expires_at', 'When the invitation expires', '2026-10-08 12:00'),
            TemplateVariable::collection('invitation.modules', 'The modules switched on when accepting', [
                TemplateVariable::text('name', 'Name of the module', 'Invoices'),
            ], [['name' => 'Invoices'], ['name' => 'Reminders']]),
        ]);
    }

    public static function links(): array
    {
        return [
            'accept' => NotificationLink::make(
                'Accept the invitation',
                static fn (array $context, object $notifiable): ?string => $context['invitation']['accept_url'] ?? null,
            ),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        return match ($locale) {
            Locale::PT_BR => new ChannelDefaults(
                title: '{% inviter.name %} convidou você para o Jarvis',
                body: '<p>{% inviter.name %} convidou você para entrar no Jarvis, no espaço {% workspace.name %}.</p>'
                    .'{% if invitation.modules %}<p>Você terá acesso a:</p><ul>{% for module in invitation.modules %}<li>{% module.name %}</li>{% endfor %}</ul>{% endif %}'
                    .'<p>O convite vale até {% invitation.expires_at %}.</p>',
                actions: [['link_key' => 'accept', 'label' => 'Aceitar o convite']],
            ),
            default => new ChannelDefaults(
                title: '{% inviter.name %} invited you to Jarvis',
                body: '<p>{% inviter.name %} invited you to join {% workspace.name %} on Jarvis.</p>'
                    .'{% if invitation.modules %}<p>You will have access to:</p><ul>{% for module in invitation.modules %}<li>{% module.name %}</li>{% endfor %}</ul>{% endif %}'
                    .'<p>The invitation is valid until {% invitation.expires_at %}.</p>',
                actions: [['link_key' => 'accept', 'label' => 'Accept the invitation']],
            ),
        };
    }

    public function context(object $notifiable): array
    {
        return [
            'inviter' => ['name' => $this->invitation->invitedBy->name],
            'workspace' => ['name' => (string) $this->invitation->workspace?->name],
            'invitation' => [
                'email' => $this->invitation->email,
                'expires_at' => $this->invitation->expires_at,
                'modules' => $this->invitation->modules()
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (Module $module): array => ['name' => AppNavigation::moduleLabel($module)])
                    ->all(),
                'accept_url' => $this->acceptUrl,
            ],
        ];
    }
}
