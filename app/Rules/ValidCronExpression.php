<?php

namespace App\Rules;

use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A five-field cron expression `dragonmantank/cron-expression` can run
 * (`0 9 * * 1-5`), or one of its macros (`@daily`), for the `custom_cron`
 * preset of a recurrence (US-12.1).
 *
 * An empty value passes; pair it with `required` when the expression is
 * mandatory.
 */
class ValidCronExpression implements ValidationRule
{
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

        if (! is_string($value) || ! CronExpression::isValidExpression(trim($value))) {
            $fail(__('The cron expression is not valid.'));
        }
    }
}
