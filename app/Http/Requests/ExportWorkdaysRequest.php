<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ExportWorkdaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    public function rules(): array
    {
        // Compare calendar dates in the office timezone, including when UTC
        // still belongs to yesterday. Future planned days are not exportable.
        $today = CarbonImmutable::today(config('app.timezone'))->toDateString();
        $endDateRules = ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:'.$today];

        // Laravel's cross-field date comparison expects a scalar sibling value.
        // Let start_date's own rules reject malformed arrays without a 500.
        if (is_string($this->input('start_date'))) {
            $endDateRules[] = 'after_or_equal:start_date';
        }

        return [
            'start_date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'end_date' => $endDateRules,
        ];
    }

    public function attributes(): array
    {
        return ['start_date' => 'תאריך ההתחלה', 'end_date' => 'תאריך הסיום'];
    }
}
