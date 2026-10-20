<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AvatarStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarController extends Controller
{
    /**
     * Serve the viewer's own image or an active worker's coworker/birthday image.
     * Managers do not gain access to another user's editable profile.
     */
    public function show(Request $request, User $user, AvatarStorage $avatars): BinaryFileResponse|StreamedResponse
    {
        $isOwnAvatar = $request->user()->is($user);
        $isActiveWorker = $user->is_active && $user->role === 'worker';

        abort_unless($isOwnAvatar || $isActiveWorker, 404);

        return $avatars->response($user->photo_path);
    }
}
