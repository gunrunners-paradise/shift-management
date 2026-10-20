<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $fillable = [
        'worker_id', 'shift_date', 'period', 'cancelled_at', 'cancelled_by',
        'cancellation_reason', 'cancellation_note',
    ];

    protected function casts(): array
    {
        return [
            'shift_date' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Persist one cancellation without overwriting its original audit details.
     * The caller must own the date lock within its surrounding transaction.
     * The period and date stay unchanged, including after a replacement.
     */
    public function cancel(int $actorId, string $reason, CarbonImmutable $cancelledAt, ?string $note = null): void
    {
        if ($this->cancelled_at !== null) {
            return;
        }

        $this->forceFill([
            'cancelled_at' => $cancelledAt,
            'cancelled_by' => $actorId,
            'cancellation_reason' => $reason,
            'cancellation_note' => $note,
        ])->save();
    }
}
