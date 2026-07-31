<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership numbers are now only allocated once a membership's first
     * payment is confirmed (see MembershipService::activateOnPayment) —
     * unpaid Draft/PendingPayment rows have no number until then.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('membership_number', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('membership_number', 20)->nullable(false)->change();
        });
    }
};
