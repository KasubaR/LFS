<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SatelliteAndPlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_satellite_seeder_creates_seven_active_satellites(): void
    {
        $this->seed(SatelliteSeeder::class);

        $this->assertSame(7, Satellite::query()->count());
        $this->assertSame(7, Satellite::query()->where('is_active', true)->count());
        $this->assertNotNull(Satellite::query()->where('slug', 'chamba-valley')->first());
        $this->assertNotNull(Satellite::query()->where('slug', 'virtual')->first());
    }

    public function test_membership_plan_seeder_creates_three_annual_payment_options(): void
    {
        $this->seed(MembershipPlanSeeder::class);

        $annual = MembershipPlan::query()->where('billing_cycle', BillingCycle::Annual)->first();
        $semiAnnual = MembershipPlan::query()->where('billing_cycle', BillingCycle::SemiAnnual)->first();
        $quarterly = MembershipPlan::query()->where('billing_cycle', BillingCycle::Quarterly)->first();

        $this->assertNotNull($annual);
        $this->assertSame('Full annual payment', $annual->name);
        $this->assertSame(1000.00, (float) $annual->price);
        $this->assertSame(12, $annual->duration_months);

        $this->assertNotNull($semiAnnual);
        $this->assertSame('K500 initial payment', $semiAnnual->name);
        $this->assertSame(500.00, (float) $semiAnnual->price);
        $this->assertSame(6, $semiAnnual->duration_months);

        $this->assertNotNull($quarterly);
        $this->assertSame('K250 initial payment', $quarterly->name);
        $this->assertSame(250.00, (float) $quarterly->price);
        $this->assertSame(3, $quarterly->duration_months);
    }
}
