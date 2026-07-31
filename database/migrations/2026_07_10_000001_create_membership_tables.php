<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255)->unique();
            $table->string('phone', 30)->default('');
            $table->string('satellite', 100)->default('');
            $table->timestamps();

            $table->index('created_at');
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

            $table->index('status');
            $table->index('membership_year');
            $table->index('period_end');
            $table->index('created_at');

            $table->foreign('member_id')
                ->references('id')
                ->on('members')
                ->cascadeOnDelete();
        });

        Schema::create('membership_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('membership_id');
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->char('currency', 3)->default('ZMW');
            $table->string('payment_method', 30)->default('mobile_money');
            $table->string('status', 20)->default('pending');
            $table->string('lenco_transaction_id', 255)->nullable()->unique();
            $table->string('lenco_reference', 255)->nullable()->unique();
            $table->string('lenco_provider', 20)->nullable();
            $table->string('lenco_status', 50)->nullable();
            $table->json('lenco_response')->nullable();
            $table->string('transaction_id', 255)->nullable()->unique();
            $table->text('payment_instructions')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
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

        Schema::create('membership_status_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('membership_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('trigger', 20);
            $table->string('actor', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('membership_id');
            $table->index('created_at');

            $table->foreign('membership_id')
                ->references('id')
                ->on('memberships')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_status_logs');
        Schema::dropIfExists('membership_payments');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('members');
    }
};
