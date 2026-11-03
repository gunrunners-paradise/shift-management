<?php

namespace App\Http\Requests;

class ProcessSignupRequestsRequest extends SignupManagementRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:4096'],
            'role' => ['prohibited'],
            'action' => ['prohibited'],
            'scope' => ['prohibited'],
            'request_id' => ['prohibited'],
        ];
    }
}
