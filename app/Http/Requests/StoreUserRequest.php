<?php

namespace App\Http\Requests;

use App\Rules\PasswordByteLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->username)) {
            $this->merge(['username' => Str::lower(trim($this->username))]);
        }
    }

    /** These rules also validate the interactive first-manager setup. */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/', Rule::unique('users')],
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'gender' => ['required', Rule::in(['female', 'male', 'other'])],
            // A birthday is a local calendar date; it is not an instant in UTC.
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'role' => ['required', Rule::in(['worker', 'manager'])],
            'password' => [
                'required', 'string', Password::min(12), 'max:72', 'confirmed',
                new PasswordByteLimit,
            ],
        ];
    }
}
