<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'annual' => 'Full annual payment',
            'semi_annual' => 'K500 initial payment',
            'quarterly' => 'K250 initial payment',
        ];

        foreach ($names as $billingCycle => $name) {
            DB::table('membership_plans')
                ->where('billing_cycle', $billingCycle)
                ->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        $names = [
            'annual' => 'Annual',
            'semi_annual' => 'Semi Annual',
            'quarterly' => 'Quarterly',
        ];

        foreach ($names as $billingCycle => $name) {
            DB::table('membership_plans')
                ->where('billing_cycle', $billingCycle)
                ->update(['name' => $name]);
        }
    }
};
