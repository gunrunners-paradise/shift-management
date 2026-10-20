<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnblockDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['shift_date' => $this->route('date')]);
    }

    public function rules(): array
    {
        return [
            'shift_date' => ['required', 'date_format:Y-m-d'],
            'confirm_unblock' => ['required', 'accepted'],
        ];
    }
}
