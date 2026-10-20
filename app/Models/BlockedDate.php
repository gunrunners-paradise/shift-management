<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedDate extends Model
{
    protected $primaryKey = 'date';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['date', 'reason', 'blocked_by'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date'];
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }
}
