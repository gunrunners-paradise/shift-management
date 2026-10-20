<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeactivateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    public function rules(): array
    {
        return ['confirm_deactivation' => ['required', 'accepted']];
    }
}
