<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('satellites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('town', 100)->default('Lusaka');
            $table->string('slug', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('satellites');
    }
};
