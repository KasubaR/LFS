<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            // 30 April of the registration year, set only when start_date falls
            // within Jan 1 - Apr 30 (the normal renewal window); null otherwise,
            // meaning full payment was required upfront with no grace period.
            // See MembershipService::computeMembershipYearDates() and
            // SuspendUnpaidMembershipsCommand.
            $table->date('grace_period_ends_at')->nullable()->after('renewal_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('grace_period_ends_at');
        });
    }
};
