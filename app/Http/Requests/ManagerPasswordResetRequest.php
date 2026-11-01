<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class ManagerPasswordResetRequest extends FormRequest
{
    protected $errorBag = 'passwordReset';

    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        // Own-password changes still verify the current password in Profile.
        return $actor?->is_active && $actor->isManager()
            && $target instanceof User && ! $actor->is($target);
    }

    protected function prepareForValidation(): void
    {
        // The route selects the account. This presentation ID only reopens the
        // correct inline form after validation, even if submitted maliciously.
        $this->merge(['password_form_id' => $this->route('user')?->getKey()]);
    }

    public function rules(): array
    {
        $passwordRules = (new UpdatePasswordRequest)->rules()['password'];
        // Bcrypt rejects NUL bytes. Return a normal validation error rather
        // than letting malformed input cause a hashing exception after submit.
        $passwordRules[] = 'not_regex:/\x00/';

        return [
            // Reuse the exact self-service password and confirmation rules;
            // recovery intentionally does not require the forgotten password.
            'password' => $passwordRules,
            'password_form_id' => ['required', 'integer', 'min:1'],
            'current_password' => ['prohibited'],
            'username' => ['prohibited'],
            'role' => ['prohibited'],
            'is_active' => ['prohibited'],
            'user_id' => ['prohibited'],
            'worker_id' => ['prohibited'],
            'first_name' => ['prohibited'],
            'last_name' => ['prohibited'],
            'gender' => ['prohibited'],
            'birth_date' => ['prohibited'],
            'photo_path' => ['prohibited'],
            'photo' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['password.not_regex' => 'הסיסמה מכילה תו שאינו נתמך. יש לבחור סיסמה אחרת.'];
    }

    /**
     * Keep failed input limited to the route-selected form, never credentials.
     * Laravel's normal handler flashes the original HTTP request, which does
     * not contain prepareForValidation()'s normalized presentation ID. An
     * explicit response also prevents a submitted _error_bag from moving the
     * errors into the unrelated account-creation form.
     */
    protected function failedValidation(Validator $validator): void
    {
        $response = null;

        if (! $this->expectsJson()) {
            $response = $this->redirector->to($this->dashboardRedirectUrl())
                ->withErrors($validator, $this->errorBag)
                ->withInput(['password_form_id' => $this->route('user')->getKey()]);
        }

        throw new ValidationException($validator, $response, $this->errorBag);
    }

    /** Preserve account pagination without accepting an external return URL. */
    public function dashboardRedirectUrl(): string
    {
        $page = filter_var($this->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $parameters = $page !== false && $page > 1 ? ['page' => $page] : [];

        return route('admin.users.index', $parameters).'#account-list';
    }

    protected function getRedirectUrl(): string
    {
        return $this->dashboardRedirectUrl();
    }
}
