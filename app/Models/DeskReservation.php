<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeskReservation extends Model
{
    protected $fillable = [
        'worker_id', 'desk_id', 'reservation_date', 'start_time', 'end_time',
        'cancelled_at', 'cancelled_by', 'cancellation_reason', 'cancellation_note',
    ];

    protected function casts(): array
    {
        return ['reservation_date' => 'immutable_date', 'cancelled_at' => 'immutable_datetime'];
    }

    /** Local TIME values remain strings; the UI consistently receives HH:MM. */
    protected function startTime(): Attribute
    {
        return Attribute::make(get: fn (string $value): string => substr($value, 0, 5));
    }

    protected function endTime(): Attribute
    {
        return Attribute::make(get: fn (string $value): string => substr($value, 0, 5));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function desk(): BelongsTo
    {
        return $this->belongsTo(Desk::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function startsAt(): CarbonImmutable
    {
        return $this->reservation_date->setTimeFromTimeString($this->start_time);
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->reservation_date->setTimeFromTimeString($this->end_time);
    }

    /**
     * Preserve the original interval and first cancellation's audit details.
     * Caller must hold the date or desk serialization lock in its transaction.
     * Desk deactivation deliberately holds only the desk lock under the manager
     * gate: acquiring dates after that desk would invert workers' lock order.
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
