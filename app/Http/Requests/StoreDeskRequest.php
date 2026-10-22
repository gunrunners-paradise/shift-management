<?php

namespace App\Http\Requests;

use App\Models\Desk;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    protected function prepareForValidation(): void
    {
        $desk = $this->route('desk');
        // Scope flashed input to the failed inline form; the route owns identity.
        $this->merge(['desk_form_id' => $desk instanceof Desk ? $desk->getKey() : 0]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'is_active' => ['prohibited'],
            'deactivated_by' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
        ];
    }
}
