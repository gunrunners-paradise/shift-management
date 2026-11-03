<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\SignupRequest;
use App\Services\SignupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SignupController extends Controller
{
    public function create(string $signupRole): View
    {
        return view('auth.signup', ['signupRole' => $signupRole]);
    }

    public function store(SignupRequest $request, string $signupRole, SignupService $signups): RedirectResponse|Response
    {
        try {
            $created = $signups->submit($signupRole, $request->validated());
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            // A duplicate may appear between validation and the locked write.
            // Keep its feedback as private and password-free as form validation.
            return redirect()->route('signup.create', ['signupRole' => $signupRole])
                ->withErrors($exception->errors())->withInput($request->safeFormInput());
        }

        if (! $created) {
            return response()->view('auth.signup-closed', ['signupRole' => $signupRole], 403);
        }

        $request->session()->put('signup_submitted', true);

        return redirect()->route('signup.submitted');
    }

    public function submitted(Request $request): View
    {
        // The confirmation is a submission receipt, not a public account-status
        // lookup. Keep only a boolean, never names, credentials or pending IDs.
        abort_unless($request->session()->get('signup_submitted') === true, 404);

        return view('auth.signup-submitted');
    }
}
