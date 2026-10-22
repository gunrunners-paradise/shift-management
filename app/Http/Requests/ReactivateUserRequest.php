<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReactivateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    public function rules(): array
    {
        // The route identifies the account. Reactivation needs no credentials
        // or editable account fields from the manager's form.
        return [];
    }
}
