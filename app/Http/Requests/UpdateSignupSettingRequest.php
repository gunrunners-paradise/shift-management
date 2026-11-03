<?php

namespace App\Http\Requests;

class UpdateSignupSettingRequest extends SignupManagementRequest
{
    public function rules(): array
    {
        return ['is_enabled' => ['required', 'boolean'], 'role' => ['prohibited']];
    }

    public function attributes(): array
    {
        return ['is_enabled' => 'מצב ההרשמה'];
    }
}
