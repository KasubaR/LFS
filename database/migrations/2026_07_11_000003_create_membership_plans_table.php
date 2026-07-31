<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('billing_cycle', 20);
            $table->decimal('price', 10, 2);
            $table->unsignedTinyInteger('duration_months');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('billing_cycle');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
