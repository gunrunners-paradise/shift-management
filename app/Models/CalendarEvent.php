<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    public const KINDS = [
        'event' => 'אירוע',
        'holiday' => 'חג',
    ];

    protected $fillable = ['title', 'date', 'description', 'kind', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date'];
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
