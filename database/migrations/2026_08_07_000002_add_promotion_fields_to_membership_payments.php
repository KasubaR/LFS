<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_payments', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->after('plan_id')
                ->constrained('promotions')->nullOnDelete();
            // The actual Kwacha amount discounted, snapshotted at payment time so
            // later edits/deletes of the promotion never rewrite payment history.
            $table->decimal('discount_amount', 12, 2)->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('membership_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('discount_amount');
        });
    }
};
