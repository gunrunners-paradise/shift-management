<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignupSetting extends Model
{
    protected $primaryKey = 'role';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['role', 'is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}
