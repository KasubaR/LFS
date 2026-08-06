<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_payments', function (Blueprint $table) {
            // The amount actually requested via the current in-flight Lenco
            // charge (set when the MoMo prompt is sent, see
            // MembershipPaymentController::initiate()). verify()/webhook add
            // this to the existing amount_paid instead of assuming any
            // successful charge pays off the payment's full amount — needed
            // now that a first payment/top-up can be less than the full
            // balance (see the Jan-Dec grace-period redesign).
            $table->decimal('pending_charge_amount', 12, 2)->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('membership_payments', function (Blueprint $table) {
            $table->dropColumn('pending_charge_amount');
        });
    }
};
