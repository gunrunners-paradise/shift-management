<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->username)) {
            $this->merge(['username' => Str::lower(trim($this->username))]);
        }
    }

    public function rules(): array
    {
        $rules = (new StoreUserRequest)->rules();
        $rules['username'][] = Rule::unique('pending_signup_requests');
        $rules['password'][] = 'not_regex:/\x00/';

        // The route supplies the role. Public input cannot set authority,
        // account state, a stored hash, or another person's stable identity.
        foreach (['role', 'signupRole', 'is_active', 'user_id', 'worker_id', 'id', 'session_version', 'remember_token', 'photo', 'photo_path', 'review_error', 'current_password'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'username.unique' => 'לא ניתן להשתמש בשם המשתמש הזה. יש לבחור שם משתמש אחר או לפנות למנהל/ת.',
            'password.not_regex' => 'הסיסמה מכילה תו שאינו נתמך. יש לבחור סיסמה אחרת.',
            '*.prohibited' => 'הבקשה כוללת שדה שאינו מותר. יש להשתמש בשדות הטופס בלבד.',
        ];
    }

    /** Flash only ordinary scalar form fields; credentials never enter session input. */
    protected function failedValidation(Validator $validator): void
    {
        $response = null;

        if (! $this->expectsJson()) {
            $response = $this->redirector->to(route('signup.create', ['signupRole' => $this->route('signupRole')]))
                ->withErrors($validator)->withInput($this->safeFormInput());
        }

        throw new ValidationException($validator, $response);
    }

    /** The same safe input is used if a duplicate appears after validation. */
    public function safeFormInput(): array
    {
        $safeInput = [];

        foreach (['username', 'first_name', 'last_name', 'gender', 'birth_date'] as $field) {
            if (is_string($this->input($field))) {
                $safeInput[$field] = $this->input($field);
            }
        }

        return $safeInput;
    }
}
