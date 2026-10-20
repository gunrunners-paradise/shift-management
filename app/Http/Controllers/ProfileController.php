<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Services\ProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'today' => CarbonImmutable::now(config('app.timezone'))->startOfDay(),
        ]);
    }

    public function update(UpdateProfileRequest $request, ProfileService $profiles): RedirectResponse
    {
        $user = $profiles->update($request->user(), $request->validated(), $request->file('photo'));
        Auth::setUser($user);

        return redirect()->route('profile.edit')->with('status', 'פרטי הפרופיל עודכנו בהצלחה.');
    }

    public function updatePassword(UpdatePasswordRequest $request, ProfileService $profiles): RedirectResponse
    {
        $user = $profiles->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
            $request->session()->getId(),
        );

        // AuthenticateSession stores the refreshed hash when the response
        // returns. Other sessions carrying the old hash fail their next request.
        Auth::setUser($user);
        $request->session()->regenerate();

        return redirect()->route('profile.edit')->with('status', 'הסיסמה עודכנה בהצלחה. התחברויות אחרות הסתיימו.');
    }
}
