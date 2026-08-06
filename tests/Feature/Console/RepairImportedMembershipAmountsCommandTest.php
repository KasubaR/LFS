<?php

namespace Tests\Feature\Console;

use App\Enums\MembershipPaymentStatus;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\User;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepairImportedMembershipAmountsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    /**
     * @return array{membership: Membership, payment: MembershipPayment}
     */
    private function importedAnnualPayment(string $startDate, float $amount): array
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->where('billing_cycle', 'annual')->firstOrFail();

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

        $payment = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => $amount,
            'amount_paid' => $amount,
            'currency' => 'ZMW',
            'payment_reference' => $membership->membership_number,
            'payment_gateway' => 'import',
            'status' => MembershipPaymentStatus::Paid,
        ]);

        return ['membership' => $membership, 'payment' => $payment];
    }

    public function test_k900_annual_payment_is_honored_in_full(): void
    {
        ['payment' => $payment] = $this->importedAnnualPayment('2026-03-01', 900.0);

        $this->artisan('member-import:repair-amounts')->assertSuccessful();

        $payment->refresh();

        // K900 honored as paid in full, not bumped to the K1000 list price
        // with a K100 shortfall recorded as still owed. There's no date
        // cutoff — every imported K900 Annual row gets this treatment.
        $this->assertSame(900.0, (float) $payment->amount);
        $this->assertSame(900.0, (float) $payment->amount_paid);
        $this->assertSame(MembershipPaymentStatus::Paid, $payment->status);
    }

    public function test_rerunning_the_command_keeps_the_k900_payment_paid_in_full(): void
    {
        ['payment' => $payment] = $this->importedAnnualPayment('2025-11-15', 900.0);

        $this->artisan('member-import:repair-amounts')->assertSuccessful();
        $this->artisan('member-import:repair-amounts')->assertSuccessful();

        $payment->refresh();

        $this->assertSame(900.0, (float) $payment->amount);
        $this->assertSame(900.0, (float) $payment->amount_paid);
        $this->assertSame(MembershipPaymentStatus::Paid, $payment->status);
    }

    public function test_full_price_annual_payment_is_unaffected(): void
    {
        ['payment' => $payment] = $this->importedAnnualPayment('2026-03-01', 1000.0);

        $this->artisan('member-import:repair-amounts')->assertSuccessful();

        $payment->refresh();

        $this->assertSame(1000.0, (float) $payment->amount);
        $this->assertSame(1000.0, (float) $payment->amount_paid);
        $this->assertSame(MembershipPaymentStatus::Paid, $payment->status);
    }

    public function test_dry_run_does_not_write_k900_annual_changes(): void
    {
        ['payment' => $payment] = $this->importedAnnualPayment('2025-11-15', 900.0);
        $payment->forceFill(['amount' => 1000.0, 'status' => MembershipPaymentStatus::PartiallyPaid])->save();

        $this->artisan('member-import:repair-amounts', ['--dry-run' => true])->assertSuccessful();

        $payment->refresh();

        $this->assertSame(1000.0, (float) $payment->amount);
        $this->assertSame(MembershipPaymentStatus::PartiallyPaid, $payment->status);
    }
}
