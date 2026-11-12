<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // Null = any membership year. Set = only payments for that year
            // (e.g. an early-bird that runs in Nov/Dec but is for next year).
            $table->unsignedSmallInteger('membership_year')->nullable()->after('plan_id');
            // all | renewals | new
            $table->string('applies_to', 20)->default('all')->after('membership_year');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['membership_year', 'applies_to']);
        });
    }
};
