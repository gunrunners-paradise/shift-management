<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        // Bind this login to the password hash that was actually verified. If a
        // concurrent password change revokes sessions before this login finishes,
        // auth.session will still reject its old hash on the next request.
        $guard = Auth::guard();
        $passwordHash = $guard->hashPasswordForCookie($request->user()->getAuthPassword());
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $passwordHash);

        // Always land on the role's actual page, including after an expired session.
        return redirect()->route($request->user()->homeRoute());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
