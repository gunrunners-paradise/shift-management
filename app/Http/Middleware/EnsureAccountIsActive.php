<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
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
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'username' => 'החשבון אינו פעיל. יש לפנות למנהל/ת.',
            ]);
        }

        Auth::setUser($currentUser);

        return $next($request);
    }
}
