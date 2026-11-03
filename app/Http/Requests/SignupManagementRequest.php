<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/** Common authority and isolated feedback for the signup-management forms. */
abstract class SignupManagementRequest extends FormRequest
{
    protected $errorBag = 'signupManagement';

    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->isManager();
    }

    protected function failedValidation(Validator $validator): void
    {
        $response = null;

        if (! $this->expectsJson()) {
            $response = $this->redirector->to(route('admin.users.index').'#signup-requests')
                ->withErrors($validator, $this->errorBag)->withInput([]);
        }

        throw new ValidationException($validator, $response, $this->errorBag);
    }

    public function attributes(): array
    {
        return [
            'action' => 'הפעולה', 'scope' => 'בחירת הבקשות',
            'request_id' => 'מזהה הבקשה', 'token' => 'אישור הפעולה',
        ];
    }
}
