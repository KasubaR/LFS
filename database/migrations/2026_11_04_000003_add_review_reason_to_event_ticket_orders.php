<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_ticket_orders', function (Blueprint $table): void {
            // Why an order is in payment_review_required (amount_mismatch,
            // capacity_unavailable, discount_unavailable, event_cancelled, ...).
            $table->string('review_reason', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_ticket_orders', function (Blueprint $table): void {
            $table->dropColumn('review_reason');
        });
    }
};
