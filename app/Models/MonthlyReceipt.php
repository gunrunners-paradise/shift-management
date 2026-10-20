<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyReceipt extends Model
{
    protected $fillable = ['worker_id', 'month', 'has_receipts'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'has_receipts' => 'boolean',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }
}
