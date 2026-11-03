<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_settings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('role', 10)->primary();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });

        // These are independent switches. Installing the feature opens neither.
        foreach (['worker', 'manager'] as $role) {
            DB::table('signup_settings')->insert([
                'role' => $role, 'is_enabled' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('pending_signup_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            // One shared namespace for both signup pages, with the same
            // collation and length as users.username. Names need not be unique.
            $table->string('username', 60)->unique();
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('gender', 10);
            $table->date('birth_date')->nullable();
            $table->string('role', 10);
            $table->string('password');
            $table->string('review_error')->nullable();
            $table->timestamps();
            $table->index(['role', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_signup_requests');
        Schema::dropIfExists('signup_settings');
    }
};
