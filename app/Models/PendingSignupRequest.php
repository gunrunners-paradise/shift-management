<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingSignupRequest extends Model
{
    protected $fillable = [
        'username', 'first_name', 'last_name', 'gender', 'birth_date',
        'role', 'password', 'review_error',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['birth_date' => 'immutable_date', 'password' => 'hashed'];
    }

    public function fullName(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function roleLabel(): string
    {
        if ($this->role === 'worker') {
            return 'עובד/ת';
        }

        return match ($this->gender) {
            'female' => 'מנהלת',
            'male' => 'מנהל',
            default => 'מנהל/ת',
        };
    }
}
