<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;

class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Annual',
                'billing_cycle' => BillingCycle::Annual,
                'price' => 1000.00,
                'duration_months' => 12,
            ],
            [
                'name' => 'Semi Annual',
                'billing_cycle' => BillingCycle::SemiAnnual,
                'price' => 500.00,
                'duration_months' => 6,
            ],
            [
                'name' => 'Quarterly',
                'billing_cycle' => BillingCycle::Quarterly,
                'price' => 250.00,
                'duration_months' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            MembershipPlan::query()->updateOrCreate(
                ['billing_cycle' => $plan['billing_cycle']],
                [
                    'name' => $plan['name'],
                    'price' => $plan['price'],
                    'duration_months' => $plan['duration_months'],
                    'is_active' => true,
                ]
            );
        }
    }
}
