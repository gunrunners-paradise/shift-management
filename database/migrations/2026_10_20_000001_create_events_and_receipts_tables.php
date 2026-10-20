<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('title', 100);
            // One local calendar date, with no recurrence or date range.
            $table->date('date')->index();
            $table->string('description', 500)->nullable();
            $table->string('kind', 10);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('monthly_receipts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('worker_id')->constrained('users')->restrictOnDelete();
            // Every calendar month is keyed by its first local date.
            $table->date('month');
            $table->boolean('has_receipts')->default(false);
            $table->timestamps();
            $table->unique(['worker_id', 'month']);
            $table->index(['month', 'has_receipts']);
        });

        // Canonical month keys make the unique index protect calendar months,
        // including a future caller accidentally passing a different day.
        DB::statement('ALTER TABLE monthly_receipts ADD CONSTRAINT monthly_receipts_first_day CHECK (DAYOFMONTH(month) = 1)');

        // MariaDB represents BOOLEAN as TINYINT; exclude other numeric values.
        DB::statement('ALTER TABLE monthly_receipts ADD CONSTRAINT monthly_receipts_boolean CHECK (has_receipts IN (0, 1))');
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_receipts');
        Schema::dropIfExists('calendar_events');
    }
};
