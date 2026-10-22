<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desks', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name', 60);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('desk_reservations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('worker_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('desk_id')->constrained('desks')->restrictOnDelete();
            $table->date('reservation_date');
            $table->foreign('reservation_date')->references('date')->on('calendar_dates')->restrictOnDelete();
            // DATE and TIME retain the chosen local day and hours without UTC
            // conversion. Cancellation timestamps are separate audit metadata.
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 32)->nullable();
            $table->string('cancellation_note', 150)->nullable();
            $table->timestamps();

            // Canceled rows release the daily slot without losing history.
            // MariaDB allows multiple NULLs, but only one active value of 1.
            $table->unsignedTinyInteger('active_slot')->nullable()
                ->storedAs('CASE WHEN cancelled_at IS NULL THEN 1 ELSE NULL END');
            $table->unique(['worker_id', 'reservation_date', 'active_slot'], 'desk_reservations_one_active_per_worker_date');
            $table->index(['desk_id', 'reservation_date', 'cancelled_at', 'start_time', 'end_time'], 'desk_reservations_availability');
            $table->index(['reservation_date', 'cancelled_at']);
        });

        // Freeze the approved Stage 5 bounds in this historical migration.
        // Transactions additionally protect interval overlap: a unique index
        // cannot express arbitrary overlapping times on the same desk.
        DB::statement("ALTER TABLE desk_reservations ADD CONSTRAINT desk_reservations_office_hours CHECK (start_time >= '09:00:00' AND end_time <= '16:30:00' AND TIME_TO_SEC(end_time) - TIME_TO_SEC(start_time) >= 3600)");
        DB::statement('ALTER TABLE desk_reservations ADD CONSTRAINT desk_reservations_half_hour_grid CHECK (MINUTE(start_time) IN (0, 30) AND MINUTE(end_time) IN (0, 30) AND SECOND(start_time) = 0 AND SECOND(end_time) = 0)');

        // This migration runs once. Normal application startup never reseeds
        // desks, so manager additions and deactivated identities stay intact.
        $now = now();

        for ($number = 1; $number <= 9; $number++) {
            DB::table('desks')->insert([
                'name' => 'עמדה '.$number,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_reservations');
        Schema::dropIfExists('desks');
    }
};
