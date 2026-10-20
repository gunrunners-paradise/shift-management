<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMonthlyReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->role === 'worker';
    }

    public function rules(): array
    {
        // The service compares this month with Israel's actual current month
        // again after obtaining the worker lock, including across midnight.
        return [
            'month' => ['required', 'date_format:Y-m'],
            'has_receipts' => ['required', 'boolean'],
            'week' => ['nullable', 'date_format:Y-m-d'],
            'worker_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
