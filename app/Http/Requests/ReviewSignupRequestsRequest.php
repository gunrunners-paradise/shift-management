<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ReviewSignupRequestsRequest extends SignupManagementRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'delete'])],
            'scope' => ['required', Rule::in(['all', 'single'])],
            'request_id' => ['required_if:scope,single', 'prohibited_if:scope,all', 'nullable', 'integer', 'min:1'],
            'role' => ['prohibited'],
        ];
    }
}
