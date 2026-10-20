<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->role === 'worker';
    }

    public function rules(): array
    {
        // Ownership always comes from the authenticated session. The service
        // checks dates again under locks, after any wait for another request.
        return [
            'shift_date' => ['required', 'date_format:Y-m-d'],
            'period' => ['required', 'string', Rule::in(array_keys(config('shifts.periods')))],
            'week' => ['nullable', 'date_format:Y-m-d'],
            'worker_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
