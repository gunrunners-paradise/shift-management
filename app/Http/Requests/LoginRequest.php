<?php

namespace App\Http\Requests;

use App\Rules\PasswordByteLimit;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->username)) {
            $this->merge(['username' => Str::lower(trim($this->username))]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:72', new PasswordByteLimit],
        ];
    }

    /**
     * Authenticate an active account with two short-lived rate limits.
     * Both invalid and disabled accounts receive the same credentials error.
     */
    public function authenticate(): void
    {
        $accountKey = 'login:account:'.hash('sha256', $this->username.'|'.$this->ip());
        $addressKey = 'login:address:'.hash('sha256', (string) $this->ip());

        if (RateLimiter::tooManyAttempts($accountKey, 5) || RateLimiter::tooManyAttempts($addressKey, 20)) {
            event(new Lockout($this));
            $seconds = max(RateLimiter::availableIn($accountKey), RateLimiter::availableIn($addressKey));

            throw ValidationException::withMessages([
                'username' => "בוצעו יותר מדי ניסיונות התחברות. נסו שוב בעוד {$seconds} שניות.",
            ]);
        }

        // The address limit also covers attempts spread across many usernames.
        RateLimiter::hit($addressKey, 60);

        if (! Auth::attempt([
            'username' => $this->username,
            'password' => $this->password,
            'is_active' => true,
        ])) {
            RateLimiter::hit($accountKey, 60);

            throw ValidationException::withMessages([
                'username' => 'שם המשתמש או הסיסמה שגויים, או שהחשבון אינו פעיל.',
            ]);
        }

        RateLimiter::clear($accountKey);
    }
}
