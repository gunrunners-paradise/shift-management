<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    protected function prepareForValidation(): void
    {
        // The route identifies the account. This ID only scopes failed input
        // to the correct inline form in the paginated account list.
        $this->merge(['deletion_form_id' => $this->route('user')?->getKey()]);
    }

    public function rules(): array
    {
        return [
            'confirm_deletion' => ['required', 'accepted'],
        ];
    }

    public function attributes(): array
    {
        return [
            'confirm_deletion' => 'מחיקת החשבון וההיסטוריה שלו לצמיתות',
        ];
    }
}
