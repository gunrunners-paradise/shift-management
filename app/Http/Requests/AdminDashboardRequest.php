<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    public function rules(): array
    {
        return ['receipts_month' => ['nullable', 'date_format:Y-m']];
    }
}
