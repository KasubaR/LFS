<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('membership_status_logs');
        Schema::dropIfExists('membership_payments');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('members');

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('membership_number', 20);
            $table->string('status', 30)->default('draft');
            $table->date('start_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('renewal_due_date')->nullable();
            $table->foreignId('current_plan_id')->constrained('membership_plans');
            $table->string('approval_status', 20)->default('pending');
            $table->string('approved_by', 100)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('joined_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('membership_number');
            $table->index('status');
            $table->index('expiry_date');
            $table->index(['user_id', 'status']);
        });

        Schema::create('membership_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('membership_id');
            $table->foreignId('plan_id')->constrained('membership_plans');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->char('currency', 3)->default('ZMW');
            $table->string('payment_reference', 255)->nullable()->unique();
            $table->string('payment_gateway', 30)->default('lenco');
            $table->string('status', 20)->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->date('covers_from')->nullable();
            $table->date('covers_to')->nullable();
            $table->string('lenco_transaction_id', 255)->nullable()->unique();
            $table->string('lenco_reference', 255)->nullable()->unique();
            $table->string('lenco_provider', 20)->nullable();
            $table->string('lenco_status', 50)->nullable();
            $table->json('lenco_response')->nullable();
            $table->boolean('webhook_received')->default(false);
            $table->json('webhook_payload')->nullable();
            $table->dateTime('webhook_received_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('membership_id');
            $table->index('status');
            $table->index('created_at');

            $table->foreign('membership_id')
                ->references('id')
                ->on('memberships')
                ->cascadeOnDelete();
        });

        Schema::create('membership_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('membership_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->string('actor', 100)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index('membership_id');
            $table->index('user_id');
            $table->index('event');
            $table->index('is_active');
            $table->index('created_at');

            $table->foreign('membership_id')
                ->references('id')
                ->on('memberships')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_history');
        Schema::dropIfExists('membership_payments');
        Schema::dropIfExists('memberships');

        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255)->unique();
            $table->string('phone', 30)->default('');
            $table->string('satellite', 100)->default('');
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('member_id')->unique();
            $table->string('status', 30)->default('draft');
            $table->unsignedSmallInteger('membership_year');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('fee_amount', 10, 2)->default(1000);
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('approved_by', 100)->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('renewed_at')->nullable();
            $table->boolean('is_renewal')->default(false);
            $table->timestamps();
        });
    }
};
