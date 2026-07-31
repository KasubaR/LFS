<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('filename');
            $table->string('imported_by', 120);
            $table->dateTime('imported_at');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->string('status', 30)->default('completed');
            $table->dateTime('rolled_back_at')->nullable();
            $table->json('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('membership_import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('membership_import_batches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('membership_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('row_ref', 20)->nullable();
            $table->string('row_email')->nullable();
            $table->json('row_payload')->nullable();
            $table->timestamps();

            $table->foreign('membership_id')->references('id')->on('memberships')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('membership_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_import_records');
        Schema::dropIfExists('membership_import_batches');
    }
};
