<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('username', 60)->unique();
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('gender', 10)->default('other');
            $table->date('birth_date')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('role', 10);
            $table->boolean('is_active')->default(true);
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
