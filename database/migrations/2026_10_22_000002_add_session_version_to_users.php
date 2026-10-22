<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Reactivation advances this version without changing the password.
            // Zero preserves existing accounts and sessions during this upgrade.
            $table->unsignedInteger('session_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('session_version');
        });
    }
};
