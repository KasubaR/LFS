<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // 'online' = placed on the website; 'offline' = recorded by an admin
            // (walk-in / in-person sale). Existing orders are all online.
            $table->string('channel', 10)->default('online')->after('status');
            // Offline sales only: how the customer paid (cash, mobile money, card).
            $table->string('payment_method', 30)->nullable()->after('channel');
            // Offline sales only: the admin who recorded the sale.
            $table->unsignedBigInteger('recorded_by')->nullable()->after('payment_method');

            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['channel']);
            $table->dropColumn(['channel', 'payment_method', 'recorded_by']);
        });
    }
};
