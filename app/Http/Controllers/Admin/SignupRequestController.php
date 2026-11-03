<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessSignupRequestsRequest;
use App\Http\Requests\ReviewSignupRequestsRequest;
use App\Http\Requests\SignupManagementRequest;
use App\Http\Requests\UpdateSignupSettingRequest;
use App\Services\SignupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SignupRequestController extends Controller
{
    public function updateSetting(UpdateSignupSettingRequest $request, string $signupRole, SignupService $signups): RedirectResponse
    {
        $signups->setEnabled($request->user(), $signupRole, $request->boolean('is_enabled'));
        $label = $signupRole === 'worker' ? 'ההרשמה לעובדים' : 'ההרשמה למנהלים';

        return redirect()->to(route('admin.users.index').'#signup-requests')
            ->with('status', $label.($request->boolean('is_enabled') ? ' נפתחה.' : ' נסגרה.'));
    }

    public function review(ReviewSignupRequestsRequest $request, SignupService $signups): View|RedirectResponse
    {
        try {
            $requestId = $request->validated('scope') === 'single' ? (int) $request->validated('request_id') : null;
            $review = $signups->review($request->user(), $request->validated('action'), $requestId);
        } catch (ValidationException $exception) {
            return $this->validationFailure($request, $exception);
        }

        return view('admin.signups.confirm', ['review' => $review]);
    }

    public function process(ProcessSignupRequestsRequest $request, SignupService $signups): RedirectResponse
    {
        try {
            $result = $signups->process($request->user(), $request->validated('token'));
        } catch (ValidationException $exception) {
            return $this->validationFailure($request, $exception);
        }

        $message = $result['action'] === 'approve'
            ? 'נוצרו '.$result['created'].' חשבונות. '.$result['conflicts'].' בקשות דורשות בדיקה.'
            : 'נמחקו '.$result['deleted'].' בקשות הצטרפות.';

        if ($result['skipped'] > 0) {
            $message .= ' '.$result['skipped'].' בקשות כבר טופלו ולא שונו שוב.';
        }

        return redirect()->to(route('admin.users.index').'#signup-requests')->with('status', $message);
    }

    /** Service failures use the same isolated bag without flashing the submitted token. */
    private function validationFailure(SignupManagementRequest $request, ValidationException $exception): RedirectResponse
    {
        if ($request->expectsJson()) {
            throw $exception;
        }

        return redirect()->to(route('admin.users.index').'#signup-requests')
            ->withErrors($exception->errors(), 'signupManagement')->withInput([]);
    }
}
