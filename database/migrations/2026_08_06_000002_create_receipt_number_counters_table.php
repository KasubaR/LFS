<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_number_counters', function (Blueprint $table) {
            $table->string('prefix', 20)->primary();
            $table->unsignedBigInteger('next_value')->default(1);
        });

        // No receipt numbers have ever been persisted before this migration
        // (the PDF used an ad-hoc, non-stored fallback) — start at 1.
        DB::table('receipt_number_counters')->insert([
            'prefix' => config('membership.receipt_number_prefix', 'LFS-RCT'),
            'next_value' => 1,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_number_counters');
    }
};
