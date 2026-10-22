<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A permanently deleted account no longer supplies an actor identity.
        // Keep shared office records and all existing RESTRICT foreign keys;
        // AccountDeletionService explicitly clears references before deletion.
        Schema::table('blocked_dates', function (Blueprint $table): void {
            $table->foreignId('blocked_by')->nullable()->change();
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->change();
            $table->foreignId('updated_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // MariaDB DDL commits independently of a surrounding transaction. Check
        // both tables before changing either, rather than partly rolling back
        // or inventing an actor identity for an already deleted account.
        $missingBlockActor = DB::table('blocked_dates')->whereNull('blocked_by')->exists();
        $missingEventActor = DB::table('calendar_events')
            ->whereNull('created_by')->orWhereNull('updated_by')->exists();

        if ($missingBlockActor || $missingEventActor) {
            throw new RuntimeException('Cannot restore required actor columns after account deletion. Restore an appropriate database backup instead.');
        }

        Schema::table('blocked_dates', function (Blueprint $table): void {
            $table->foreignId('blocked_by')->nullable(false)->change();
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable(false)->change();
            $table->foreignId('updated_by')->nullable(false)->change();
        });
    }
};
