<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_dates', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            // Never remove these serialization rows when reopening a date.
            // Both first-time bookings and first-time blocks lock this key.
            $table->date('date')->primary();
        });

        Schema::create('blocked_dates', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->date('date')->primary();
            $table->foreign('date')->references('date')->on('calendar_dates')->restrictOnDelete();
            $table->string('reason', 150)->nullable();
            $table->foreignId('blocked_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('worker_id')->constrained('users')->restrictOnDelete();
            $table->date('shift_date');
            $table->foreign('shift_date')->references('date')->on('calendar_dates')->restrictOnDelete();
            $table->string('period', 10);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 32)->nullable();
            $table->string('cancellation_note', 150)->nullable();
            $table->timestamps();

            // MariaDB permits multiple NULLs in a unique index. Canceled rows
            // therefore retain history while only one active row can exist.
            $table->unsignedTinyInteger('active_slot')->nullable()
                ->storedAs('CASE WHEN cancelled_at IS NULL THEN 1 ELSE NULL END');
            $table->unique(['worker_id', 'shift_date', 'active_slot'], 'shifts_one_active_per_worker_date');
            $table->index(['shift_date', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('blocked_dates');
        Schema::dropIfExists('calendar_dates');
    }
};
