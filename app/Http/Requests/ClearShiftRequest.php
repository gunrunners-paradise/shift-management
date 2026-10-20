<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClearShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->role === 'worker';
    }

    protected function prepareForValidation(): void
    {
        // The URL identifies the date being cleared; a body value cannot replace it.
        $this->merge(['shift_date' => $this->route('date')]);
    }

    public function rules(): array
    {
        return [
            'shift_date' => ['required', 'date_format:Y-m-d'],
            'week' => ['nullable', 'date_format:Y-m-d'],
            'worker_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
