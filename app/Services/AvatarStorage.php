<?php

namespace App\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AvatarStorage
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Store an already validated image outside public/, under a random name.
     * Neither the uploaded filename nor a submitted path becomes a disk path.
     */
    public function store(UploadedFile $photo): string
    {
        $extension = self::MIME_EXTENSIONS[$photo->getMimeType()] ?? null;

        if ($extension === null) {
            throw ValidationException::withMessages(['photo' => 'יש לבחור תמונת JPG, PNG או WebP.']);
        }

        $filename = Str::random(40).'.'.$extension;
        $path = 'avatars/'.$filename;

        try {
            $storedPath = $photo->storeAs('avatars', $filename, [
                'disk' => 'local',
                'visibility' => 'private',
            ]);
        } catch (Throwable $exception) {
            $this->delete($path);

            throw $exception;
        }

        if (! is_string($storedPath)) {
            // A failed filesystem write can leave a partial file behind.
            $this->delete($path);

            throw ValidationException::withMessages([
                'photo' => 'לא ניתן לשמור את התמונה כעת. נסו שוב מאוחר יותר.',
            ]);
        }

        return $storedPath;
    }

    /** Delete only application-generated avatar paths; never arbitrary files. */
    public function delete(?string $path): void
    {
        if (! $this->isAvatarPath($path)) {
            return;
        }

        if (! Storage::disk('local')->delete($path)) {
            // Do not expose a storage path or turn a committed profile update
            // into a misleading failure because old-file cleanup was denied.
            Log::warning('An unused profile image could not be removed. Check private storage permissions.');
        }
    }

    /**
     * Return image bytes with an explicit safe MIME type, or the default image.
     * Authorization belongs to AvatarController, before any file is opened.
     */
    public function response(?string $path): BinaryFileResponse|StreamedResponse
    {
        $disk = Storage::disk('local');

        if (! $this->isAvatarPath($path) || ! $disk->exists($path)) {
            return $this->defaultResponse();
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return $this->defaultResponse();
        }

        $imageHeader = fread($stream, 8192);
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($imageHeader ?: '');
        rewind($stream);

        if (! is_string($mimeType) || ! array_key_exists($mimeType, self::MIME_EXTENSIONS)) {
            fclose($stream);

            return $this->defaultResponse();
        }

        // A private file is streamed as image content. It is never published
        // through a symlink, evaluated as PHP, or served as an uploaded SVG.
        // Keep the opened handle: a concurrent replacement can safely unlink
        // the old filename without interrupting an image already being read.
        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function isAvatarPath(?string $path): bool
    {
        return is_string($path)
            && preg_match('/\Aavatars\/[A-Za-z0-9]{40}\.(jpg|png|webp)\z/', $path) === 1;
    }

    private function defaultResponse(): BinaryFileResponse
    {
        return response()->file(public_path('images/default-avatar.svg'), [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
