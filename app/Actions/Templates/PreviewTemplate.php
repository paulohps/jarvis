<?php

namespace App\Actions\Templates;

use App\Models\User;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateError;
use App\Services\Templates\TemplateFormat;
use App\Services\Templates\TemplatePreview;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateValidator;
use Illuminate\Support\Facades\App;

/**
 * Renders a template being written with the example data of its schema
 * (US-4.2; Workflow 4), in the user's language and timezone, so the user
 * sees what a recipient like them gets: the common variables on today's
 * date, dates and amounts written out, a body as sanitized HTML and a title
 * or WhatsApp message as plain text.
 *
 * A template that saving would refuse is not rendered: the preview lists
 * the same problems `ValidTemplate` reports, in the user's language. Nothing
 * is stored or sent.
 */
final readonly class PreviewTemplate
{
    public function __construct(
        private TemplateRenderer $renderer,
        private TemplateValidator $validator,
    ) {}

    public function __invoke(TemplateSchema $schema, string $template, User $user, TemplateFormat $format = TemplateFormat::Text): TemplatePreview
    {
        $builder = TemplateContextBuilder::forUser($user);
        $previousLocale = App::getLocale();

        App::setLocale($builder->locale);

        try {
            $errors = $this->validator->validate($template, $schema);

            if ($errors !== []) {
                return new TemplatePreview(
                    null,
                    $format,
                    array_map(static fn (TemplateError $error): string => $error->translated(), $errors),
                );
            }

            $context = $builder->sample($schema);

            return new TemplatePreview(
                $format === TemplateFormat::Html
                    ? $this->renderer->renderHtml($template, $context)
                    : $this->renderer->renderText($template, $context),
                $format,
            );
        } finally {
            App::setLocale($previousLocale);
        }
    }
}
