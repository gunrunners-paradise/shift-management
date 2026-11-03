<?php

namespace App\Http\Middleware;

use App\Services\SignupService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSignupIsOpen
{
    /** Refuse closed forms before input validation or expensive password hashing. */
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->route('signupRole');

        if (! app(SignupService::class)->isEnabled($role)) {
            return response()->view('auth.signup-closed', ['signupRole' => $role], 403);
        }

        return $next($request);
    }
}
