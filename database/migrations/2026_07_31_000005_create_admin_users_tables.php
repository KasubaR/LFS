<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('role');
            $table->index('is_active');
        });

        Schema::create('admin_user_satellite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->foreignId('satellite_id')->constrained('satellites')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['admin_user_id', 'satellite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_user_satellite');
        Schema::dropIfExists('admin_users');
    }
};
