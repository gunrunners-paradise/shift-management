<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeactivateDeskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    public function rules(): array
    {
        return ['confirm_deactivation' => ['required', 'accepted']];
    }

    public function attributes(): array
    {
        return ['confirm_deactivation' => 'השבתת העמדה וביטול הזמנות עתידיות והזמנות שכבר החלו'];
    }
}
