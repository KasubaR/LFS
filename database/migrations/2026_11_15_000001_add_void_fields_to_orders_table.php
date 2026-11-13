<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Offline sales that were recorded by mistake or refunded: who voided
            // them, when and why. The order itself moves to Cancelled and its
            // stock goes back to the shelf.
            $table->dateTime('voided_at')->nullable()->after('recorded_by');
            $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            $table->string('void_reason', 255)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['voided_at', 'voided_by', 'void_reason']);
        });
    }
};
