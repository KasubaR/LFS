<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticketing settings for paid events (see docs/EVENT_TICKETING_PLAN.md).
 * All columns are nullable or defaulted so existing free, members-only and
 * external-link events are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->decimal('ticket_price_member', 12, 2)->nullable();
            $table->decimal('ticket_price_non_member', 12, 2)->nullable();
            // null = unlimited capacity
            $table->unsignedInteger('ticket_capacity')->nullable();
            $table->boolean('ticket_sales_open')->default(true);
            // scheduled | postponed | cancelled
            $table->string('ticket_status', 20)->default('scheduled');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn([
                'ticket_price_member',
                'ticket_price_non_member',
                'ticket_capacity',
                'ticket_sales_open',
                'ticket_status',
            ]);
        });
    }
};
