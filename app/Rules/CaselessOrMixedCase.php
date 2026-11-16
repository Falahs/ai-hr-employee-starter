<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Upper and lower case, only where the letters have case.
 *
 * Arabic letters have no upper or lower case, so Laravel's mixedCase() refuses every Arabic
 * password however long it is, with a message («حرف كبير وحرف صغير») that reads like a bug.
 * Here a password that holds any letter outside the Latin script passes this rule; an
 * all-Latin one still needs both cases. Length (12) and a number stay required elsewhere.
 */
class CaselessOrMixedCase implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        // a letter that is not Latin: Arabic, or any other script without case
        if (preg_match('/[^\P{L}\p{Latin}]/u', $value)) {
            return;
        }

        if (! preg_match('/\p{Ll}/u', $value) || ! preg_match('/\p{Lu}/u', $value)) {
            $fail(__('ui.password.latin_mixed'));
        }
    }
}
