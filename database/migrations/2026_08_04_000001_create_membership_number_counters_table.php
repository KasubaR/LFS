<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_number_counters', function (Blueprint $table) {
            $table->string('prefix', 20)->primary();
            $table->unsignedBigInteger('next_value')->default(1);
        });

        $prefix = config('membership.membership_number_prefix', 'LFS');
        $latest = DB::table('memberships')
            ->where('membership_number', 'like', $prefix.'-%')
            ->orderByDesc('membership_number')
            ->value('membership_number');

        $next = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        DB::table('membership_number_counters')->insert([
            'prefix' => $prefix,
            'next_value' => $next,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_number_counters');
    }
};
