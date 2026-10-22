<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Recheck the account on every web request, including an existing session.
     * Deleting session rows alone cannot stop an in-flight login rewriting one.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->user();

        if ($authenticatedUser === null) {
            return $next($request);
        }

        $currentUser = User::find($authenticatedUser->getKey());

        if ($currentUser === null || ! $currentUser->is_active) {
            return $this->endSession($request, 'החשבון אינו פעיל. יש לפנות למנהל/ת.');
        }

        // A late request can recreate a deleted session row after reactivation.
        // Its older version still fails, even though the password is unchanged.
        // Sessions created before this feature belong to the initial version 0.
        $sessionVersion = $request->session()->get('auth_session_version', 0);

        if (! is_int($sessionVersion) || $sessionVersion !== $currentUser->session_version) {
            return $this->endSession($request, 'יש להתחבר מחדש כדי להמשיך להשתמש במערכת.');
        }

        Auth::setUser($currentUser);

        return $next($request);
    }

    /** Revoke this browser's authentication and explain why sign-in is needed. */
    private function endSession(Request $request, string $message): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['username' => $message]);
    }
}
