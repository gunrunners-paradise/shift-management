<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Desk extends Model
{
    protected $fillable = ['name', 'is_active', 'deactivated_at', 'deactivated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'deactivated_at' => 'immutable_datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(DeskReservation::class);
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }
}
