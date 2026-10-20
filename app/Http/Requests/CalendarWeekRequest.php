<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalendarWeekRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->role === 'worker';
    }

    public function rules(): array
    {
        return ['week' => ['nullable', 'date_format:Y-m-d']];
    }
}
