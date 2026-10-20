<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BlockDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    public function rules(): array
    {
        return [
            'shift_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:150'],
            'confirm_block' => ['required', 'accepted'],
        ];
    }
}
