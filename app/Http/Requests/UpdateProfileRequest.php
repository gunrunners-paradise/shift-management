<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active
            && in_array($this->user()->role, ['worker', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'gender' => ['required', Rule::in(['female', 'male', 'other'])],
            // A birthday is a local date. No birth year is sent to coworkers.
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'photo' => [
                'nullable', 'bail', 'file', 'image', 'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp', 'max:2048',
                'dimensions:min_width=1,min_height=1,max_width=2048,max_height=2048',
            ],
            // This endpoint edits the session owner, never an ID from the form.
            'username' => ['prohibited'],
            'role' => ['prohibited'],
            'is_active' => ['prohibited'],
            'photo_path' => ['prohibited'],
            'user_id' => ['prohibited'],
            'worker_id' => ['prohibited'],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
            'current_password' => ['prohibited'],
        ];
    }
}
