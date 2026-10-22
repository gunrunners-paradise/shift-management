<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteDeskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    protected function prepareForValidation(): void
    {
        // The route owns identity; this value only reopens the failed inline
        // form. It is not a typed name, username or password confirmation.
        $formIdentity = ['desk_form_id' => $this->route('desk')?->getKey()];
        $this->merge($formIdentity);

        // Laravel validates this FormRequest copy but flashes the original
        // HTTP request on failure. Keep its display-only ID consistent too,
        // so supplied input cannot reopen a different desk's delete form.
        request()->merge($formIdentity);
    }

    public function rules(): array
    {
        return ['confirm_deletion' => ['required', 'accepted']];
    }

    public function attributes(): array
    {
        return ['confirm_deletion' => 'מחיקת העמדה וכל ההזמנות שלה'];
    }
}
