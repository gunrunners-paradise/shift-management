<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordByteLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Bcrypt accepts at most 72 bytes. Laravel's max rule counts characters,
        // so Hebrew passwords also need a byte check at creation AND sign-in.
        if (is_string($value) && strlen($value) > 72) {
            $fail('הסיסמה ארוכה מדי. יש לבחור סיסמה באורך עד 72 בתים.');
        }
    }
}
