<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_payments', function (Blueprint $table) {
            // Distinct from payment_reference (the Lenco/gateway reference, or —
            // for legacy imports — the membership number). This is a dedicated,
            // sequential, human-readable receipt id assigned once a payment first
            // has money against it. See MembershipPaymentService::generateReceiptNumber().
            $table->string('receipt_number', 255)->nullable()->unique()->after('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::table('membership_payments', function (Blueprint $table) {
            $table->dropColumn('receipt_number');
        });
    }
};
