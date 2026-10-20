<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $fillable = [
        'username', 'first_name', 'last_name', 'gender', 'birth_date',
        'role', 'is_active', 'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function fullName(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    /** Uploaded images are served by an authenticated route, never a disk URL. */
    public function avatarUrl(): string
    {
        if ($this->photo_path === null) {
            return asset('images/default-avatar.svg');
        }

        // A replacement must change the roster snapshot even when the worker's
        // name and shift stay the same. Expose only a fingerprint, not the path.
        $photoVersion = substr(hash('sha256', $this->photo_path), 0, 12);

        return route('avatars.show', ['user' => $this->getKey(), 'v' => $photoVersion]);
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function homeRoute(): string
    {
        return $this->isManager() ? 'admin.users.index' : 'worker.home';
    }

    public function roleLabel(): string
    {
        if (! $this->isManager()) {
            return 'עובד/ת';
        }

        // An unspecified gender must not be silently displayed as male.
        return match ($this->gender) {
            'female' => 'מנהלת',
            'male' => 'מנהל',
            default => 'מנהל/ת',
        };
    }
}
