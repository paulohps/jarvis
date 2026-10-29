<?php

namespace App\Rules;

use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A `{% %}` template that parses and only uses the variables of its context
 * (US-4.1), used by the Actions that save templates. Every problem becomes a
 * message of its own, in the reader's language, with the line and column it
 * starts at: an unclosed tag, an unknown variable, a loop over something that
 * is not a list.
 *
 * An empty value passes; pair it with `required` when the text is mandatory.
 */
class ValidTemplate implements ValidationRule
{
    public function __construct(
        public TemplateSchema $schema,
        private TemplateValidator $validator = new TemplateValidator,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('validation.string')->translate();

            return;
        }

        foreach ($this->validator->validate($value, $this->schema) as $error) {
            $fail($error->translated());
        }
    }
}
