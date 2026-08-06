<?php

namespace Tests\Feature\Console;

use App\Enums\MembershipHistoryEvent;
use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipHistory;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuspendUnpaidMembershipsCommandTest extends TestCase
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
    private function activeMembershipWithPayment(?string $graceEndsAt, string $paymentStatus, float $amountPaid): array
    {
        $plan = MembershipPlan::query()->first();
        $user = User::factory()->create();

        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-'.random_int(10000, 99999),
            'status' => MembershipStatus::Active,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'start_date' => now()->subMonths(3)->toDateString(),
            'expiry_date' => now()->addMonths(9)->toDateString(),
            'grace_period_ends_at' => $graceEndsAt,
            'joined_at' => now()->subMonths(3),
        ]);

        $payment = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => 1000,
            'amount_paid' => $amountPaid,
            'currency' => 'ZMW',
            'payment_gateway' => 'lenco',
            'status' => $paymentStatus,
        ]);

        return ['membership' => $membership, 'payment' => $payment];
    }

    public function test_suspends_active_membership_whose_grace_period_passed_unpaid(): void
    {
        ['membership' => $membership] = $this->activeMembershipWithPayment(
            now()->subDay()->toDateString(),
            MembershipPaymentStatus::PartiallyPaid,
            250.00
        );

        $this->artisan('membership:suspend-unpaid')->assertSuccessful();

        $this->assertSame(MembershipStatus::Suspended, $membership->fresh()->status);
        $this->assertTrue(
            MembershipHistory::query()
                ->where('membership_id', $membership->id)
                ->where('event', MembershipHistoryEvent::Suspended)
                ->exists()
        );
    }

    public function test_does_not_suspend_membership_still_inside_the_grace_period(): void
    {
        ['membership' => $membership] = $this->activeMembershipWithPayment(
            now()->addDay()->toDateString(),
            MembershipPaymentStatus::PartiallyPaid,
            250.00
        );

        $this->artisan('membership:suspend-unpaid')->assertSuccessful();

        $this->assertSame(MembershipStatus::Active, $membership->fresh()->status);
    }

    public function test_does_not_suspend_a_fully_paid_membership(): void
    {
        ['membership' => $membership] = $this->activeMembershipWithPayment(
            now()->subDay()->toDateString(),
            MembershipPaymentStatus::Paid,
            1000.00
        );

        $this->artisan('membership:suspend-unpaid')->assertSuccessful();

        $this->assertSame(MembershipStatus::Active, $membership->fresh()->status);
    }

    public function test_does_not_touch_a_membership_with_no_grace_period_set(): void
    {
        // No grace_period_ends_at at all — e.g. a late-joiner who had to pay
        // in full upfront, so this command has nothing to enforce.
        ['membership' => $membership] = $this->activeMembershipWithPayment(
            null,
            MembershipPaymentStatus::PartiallyPaid,
            250.00
        );

        $this->artisan('membership:suspend-unpaid')->assertSuccessful();

        $this->assertSame(MembershipStatus::Active, $membership->fresh()->status);
    }

    public function test_paying_in_full_while_suspended_reinstates_to_active_without_changing_dates(): void
    {
        ['membership' => $membership, 'payment' => $payment] = $this->activeMembershipWithPayment(
            now()->subDay()->toDateString(),
            MembershipPaymentStatus::PartiallyPaid,
            250.00
        );

        $this->artisan('membership:suspend-unpaid')->assertSuccessful();
        $this->assertSame(MembershipStatus::Suspended, $membership->fresh()->status);

        $originalExpiry = $membership->fresh()->expiry_date->toDateString();

        app(MembershipService::class)->handlePaymentUpdate($payment->id, 1000.00);

        $membership->refresh();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame($originalExpiry, $membership->expiry_date->toDateString());
        $this->assertTrue(
            MembershipHistory::query()
                ->where('membership_id', $membership->id)
                ->where('event', MembershipHistoryEvent::Reinstated)
                ->exists()
        );
    }
}
