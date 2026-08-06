<?php

namespace Tests\Feature\Console;

use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\Promotion;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillK900AnnualPromotionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    private function importedPayment(string $startDate, float $amount, MembershipPlan $plan, string $status = 'paid'): MembershipPayment
    {
        $user = User::factory()->create();

        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS'.random_int(10000, 99999),
            'status' => 'active',
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'start_date' => $startDate,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        return MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => $amount,
            'amount_paid' => $amount,
            'currency' => 'ZMW',
            'payment_reference' => $membership->membership_number,
            'payment_gateway' => 'import',
            'status' => $status,
        ]);
    }

    public function test_tags_k900_annual_payments_with_a_promotion(): void
    {
        $annual = MembershipPlan::query()->where('billing_cycle', 'annual')->firstOrFail();
        $payment = $this->importedPayment('2025-11-15', 900.0, $annual);

        $this->artisan('promotions:backfill-k900-annual')->assertSuccessful();

        $payment->refresh();
        $this->assertNotNull($payment->promotion_id);
        $this->assertSame(100.0, (float) $payment->discount_amount);

        $this->assertDatabaseCount('promotions', 1);
        $promotion = Promotion::query()->firstOrFail();
        $this->assertSame($annual->id, $promotion->plan_id);
        $this->assertSame(10.0, (float) $promotion->discount_value);
        $this->assertFalse((bool) $promotion->is_active);
    }

    public function test_non_matching_payments_are_left_untouched(): void
    {
        $annual = MembershipPlan::query()->where('billing_cycle', 'annual')->firstOrFail();
        $quarterly = MembershipPlan::query()->where('billing_cycle', 'quarterly')->firstOrFail();

        $fullPrice = $this->importedPayment('2025-11-15', 1000.0, $annual);
        $differentPlan = $this->importedPayment('2025-11-15', 900.0, $quarterly);
        $notPaid = $this->importedPayment('2025-11-15', 900.0, $annual, 'partially_paid');

        $this->artisan('promotions:backfill-k900-annual')->assertSuccessful();

        $this->assertNull($fullPrice->fresh()->promotion_id);
        $this->assertNull($differentPlan->fresh()->promotion_id);
        $this->assertNull($notPaid->fresh()->promotion_id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $annual = MembershipPlan::query()->where('billing_cycle', 'annual')->firstOrFail();
        $payment = $this->importedPayment('2025-11-15', 900.0, $annual);

        $this->artisan('promotions:backfill-k900-annual', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($payment->fresh()->promotion_id);
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_command_is_idempotent_on_rerun(): void
    {
        $annual = MembershipPlan::query()->where('billing_cycle', 'annual')->firstOrFail();
        $this->importedPayment('2025-11-15', 900.0, $annual);
        $this->importedPayment('2025-12-01', 900.0, $annual);

        $this->artisan('promotions:backfill-k900-annual')->assertSuccessful();
        $this->assertDatabaseCount('promotions', 1);

        $this->artisan('promotions:backfill-k900-annual')->assertSuccessful();
        // Rerun reuses the same promotion (firstOrCreate by name) and tags nothing new.
        $this->assertDatabaseCount('promotions', 1);
    }
}
