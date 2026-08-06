<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // Null = applies to every active plan; set = scoped to one plan.
            $table->foreignId('plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->string('discount_type', 20)->default('percentage');
            $table->decimal('discount_value', 10, 2);
            $table->date('starts_at');
            $table->date('ends_at');
            // Manual pause/resume switch independent of the date window.
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
