<?php

namespace App\Http\Requests;

use App\Rules\PasswordByteLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active
            && in_array($this->user()->role, ['worker', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            // The service checks the current hash again while holding the user
            // lock, so two password changes cannot reuse a stale password.
            'current_password' => ['required', 'string', 'max:72', new PasswordByteLimit],
            'password' => [
                'required', 'string', Password::min(12), 'max:72', 'confirmed',
                new PasswordByteLimit,
            ],
            'username' => ['prohibited'],
            'role' => ['prohibited'],
            'is_active' => ['prohibited'],
            'photo_path' => ['prohibited'],
            'user_id' => ['prohibited'],
            'worker_id' => ['prohibited'],
            'first_name' => ['prohibited'],
            'last_name' => ['prohibited'],
            'gender' => ['prohibited'],
            'birth_date' => ['prohibited'],
            'photo' => ['prohibited'],
        ];
    }
}
