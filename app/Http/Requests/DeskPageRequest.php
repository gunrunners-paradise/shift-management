<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeskPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->role === 'worker';
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            // A saved link may name a desk that was deactivated since the page
            // opened. Let the read model choose another active desk safely.
            'desk_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
