<?php

namespace App\Services\Notifications;

use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;

/**
 * The variables a notification type exposes to its templates: the ones the
 * type declares plus the common ones every context offers
 * (`current_month_name`, `current_year`, ...), which `CenterNotification`
 * adds to every context.
 */
final class NotificationSchema extends TemplateSchema
{
    /**
     * @param  list<TemplateVariable>  $variables
     */
    private function __construct(private readonly array $variables) {}

    /**
     * @param  list<TemplateVariable>  $variables  the type's own variables
     */
    public static function make(array $variables): self
    {
        return new self([...$variables, ...TemplateContextBuilder::commonVariables()]);
    }

    public function variables(): array
    {
        return $this->variables;
    }
}
