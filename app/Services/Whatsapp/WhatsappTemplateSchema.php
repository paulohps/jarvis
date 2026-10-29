<?php

namespace App\Services\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateSyntaxException;
use App\Services\Templates\TemplateVariable;

/**
 * The variables of a WhatsApp message body (US-7.3; Workflow 10): the
 * common ones (`current_month_name`, `current_year`, ...) plus who receives
 * it (`recipient.name`) and who sends it (`sender.name`).
 *
 * WhatsApp shows plain text, so a body renders without any HTML: tags are
 * stripped, entities decoded and line breaks kept. It is written out in the
 * sender's language and on the sender's calendar, once per target.
 */
final class WhatsappTemplateSchema extends TemplateSchema
{
    public function __construct(private readonly TemplateRenderer $renderer = new TemplateRenderer) {}

    public function variables(): array
    {
        return [
            TemplateVariable::text('recipient.name', 'The name of the contact or group (empty for a typed number)', 'Ana'),
            TemplateVariable::text('sender.name', 'Your name', 'Paulo'),
            ...TemplateContextBuilder::commonVariables(),
        ];
    }

    /**
     * The variables for one target, written by the sender.
     *
     * @return array{recipient: array{name: string}, sender: array{name: string}, current_month_name: string, current_year: int, current_date: string, next_month_name: string}
     */
    public function context(WhatsappTarget $target, User $sender, TemplateContextBuilder $builder): array
    {
        return [
            'recipient' => ['name' => (string) $target->name],
            'sender' => ['name' => $sender->name],
            ...$builder->common(),
        ];
    }

    /**
     * The body as it goes to the target: plain text.
     *
     * @throws TemplateSyntaxException
     */
    public function render(string $body, WhatsappTarget $target, User $sender): string
    {
        $builder = TemplateContextBuilder::forUser($sender);

        return $this->renderer->renderText($body, $this->context($target, $sender, $builder));
    }
}
