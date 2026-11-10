<?php

namespace Tests\Feature\Auth;

use App\Enums\BillingCycle;
use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\LencoService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Membership pricing by date:
 *
 *   1 Jan - 30 Apr   instalments allowed (K250 / K500 / K1,000 first payment)
 *   1 May - 30 Jun   ONE full payment of K1,000. No instalments, whatever plan was picked.
 *   1 Jul onwards    ONE full payment of the reduced late-joiner fee, K500.
 *
 * These tests drive the real "Pay" prompt, so they check what is actually
 * charged to the member's phone, not just what a page says.
 */
class MembershipNoInstalmentsAfterAprilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function applicantOn(string $cycle, string $number = 'LFS-000900'): User
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->where('billing_cycle', $cycle)->firstOrFail();

        Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => $number,
            'status' => MembershipStatus::Draft,
            'current_plan_id' => $plan->id,
            'approval_status' => 'pending',
        ]);

        return $user;
    }

    /**
     * Presses "Pay" and returns the amount the member's phone was asked for.
     */
    private function chargedWhenPaying(User $user, string $reference = 'LFS-REF-X'): float
    {
        $charged = null;

        $this->mock(LencoService::class, function ($mock) use (&$charged, $reference): void {
            $mock->shouldReceive('generateReference')->andReturn($reference);
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturnUsing(
                function (array $order) use (&$charged, $reference): array {
                    $charged = (float) $order['totals']['total'];

                    return [
                        'transactionId' => 'txn-'.$reference, 'lencoReference' => 'lenco-'.$reference, 'reference' => $reference,
                        'status' => 'pay-offline', 'paymentInstructions' => 'Approve on your phone',
                        'paymentUrl' => null, 'expiresAt' => null, 'rawResponse' => [],
                    ];
                }
            );
        });

        $this->actingAs($user)->postJson('/account/payment/initiate', [
            'provider' => 'mtn', 'phone' => '+260971234567',
        ])->assertOk();

        $this->assertNotNull($charged, 'the payment prompt was sent');

        return $charged;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: float}>
     */
    public static function chargeByDate(): array
    {
        $cases = [];

        // Control: up to and including 30 April the plan sets the first payment.
        foreach ([[BillingCycle::Quarterly, 250.0], [BillingCycle::SemiAnnual, 500.0], [BillingCycle::Annual, 1000.0]] as [$cycle, $amount]) {
            $cases["30 Apr 23:00, {$cycle}"] = ['2026-04-30 23:00:00', $cycle, $amount];
        }

        // 1 May to 30 June: always the full K1,000, whichever plan was picked.
        foreach (['2026-05-01 00:00:01', '2026-05-15 10:00:00', '2026-06-15 10:00:00', '2026-06-30 23:59:00'] as $at) {
            foreach ([BillingCycle::Quarterly, BillingCycle::SemiAnnual, BillingCycle::Annual] as $cycle) {
                $cases["{$at}, {$cycle}"] = [$at, $cycle, 1000.0];
            }
        }

        // From 1 July: one full payment of the reduced late-joiner fee.
        foreach ([BillingCycle::Quarterly, BillingCycle::SemiAnnual, BillingCycle::Annual] as $cycle) {
            $cases["1 Jul, {$cycle}"] = ['2026-07-01 00:00:00', $cycle, 500.0];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chargeByDate')]
    public function test_the_amount_charged_depends_on_the_date_not_just_the_plan(string $at, string $cycle, float $expected): void
    {
        Carbon::setTestNow($at);

        $charged = $this->chargedWhenPaying($this->applicantOn($cycle));

        $this->assertSame($expected, $charged);
    }

    public function test_a_k250_plan_member_in_may_pays_k1000_in_one_go_and_gets_no_instalment_status(): void
    {
        Carbon::setTestNow('2026-05-12 10:00:00');
        $user = $this->applicantOn(BillingCycle::Quarterly);

        $this->mock(LencoService::class, function ($mock): void {
            $mock->shouldReceive('generateReference')->andReturn('LFS-REF-MAY');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 't', 'lencoReference' => 'l', 'reference' => 'LFS-REF-MAY',
                'status' => 'pay-offline', 'paymentInstructions' => null, 'paymentUrl' => null, 'expiresAt' => null, 'rawResponse' => [],
            ]);
            $mock->shouldReceive('verifyPayment')->once()->with('LFS-REF-MAY', true)->andReturn([
                'status' => 'successful', 'internalStatus' => 'completed', 'rawResponse' => [],
            ]);
        });

        $this->actingAs($user)->postJson('/account/payment/initiate', ['provider' => 'mtn', 'phone' => '+260971234567'])->assertOk();
        $this->actingAs($user)->getJson('/account/payment/verify?reference=LFS-REF-MAY')->assertOk()->assertJson(['status' => 'completed']);

        $payment = MembershipPayment::query()->firstOrFail();
        $this->assertSame('1000.00', (string) $payment->amount);
        $this->assertSame('1000.00', (string) $payment->amount_paid, 'paid in full, nothing left owing');
        $this->assertSame(MembershipPaymentStatus::Paid, $payment->status);

        $membership = Membership::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertNull($membership->grace_period_ends_at, 'no grace period, so nothing to be suspended for later');
        $this->assertSame('2026-12-31', $membership->expiry_date->toDateString());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function firstAndLastDayOfTheNoInstalmentWindow(): array
    {
        return [
            '1 May (first second after the deadline)' => ['2026-05-01 00:00:01'],
            '30 June (last minute before the K500 fee)' => ['2026-06-30 23:59:00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('firstAndLastDayOfTheNoInstalmentWindow')]
    public function test_a_member_cannot_end_up_partially_paid_after_30_april(string $at): void
    {
        Carbon::setTestNow($at);

        $user = $this->applicantOn(BillingCycle::Quarterly);
        $this->chargedWhenPaying($user, 'LFS-REF-WINDOW');

        $payment = MembershipPayment::query()->firstOrFail();
        $this->assertSame('1000.00', (string) $payment->amount);
        $this->assertSame('1000.00', (string) $payment->pending_charge_amount, 'the pending charge is the whole fee');
        $this->assertSame('0.00', (string) $payment->amount_paid, 'nothing is credited until the full charge is confirmed');
        $this->assertNotSame(MembershipPaymentStatus::PartiallyPaid, $payment->status);
    }

    public function test_the_pay_button_and_page_promise_the_full_amount_in_may_and_june(): void
    {
        foreach (['2026-05-01 00:00:01', '2026-06-30 23:59:00'] as $at) {
            Carbon::setTestNow($at);
            MembershipPayment::query()->delete();
            Membership::query()->delete();

            $user = $this->applicantOn(BillingCycle::Quarterly, 'LFS-0009'.random_int(10, 99));

            $this->actingAs($user)->get('/account')
                ->assertOk()
                ->assertSee('Pay K1,000', false)
                ->assertDontSee('Pay K250', false)
                ->assertSee('data-amount="1000"', false);
        }

        // Control: inside the window the same member is offered their K250.
        Carbon::setTestNow('2026-04-30 12:00:00');
        MembershipPayment::query()->delete();
        Membership::query()->delete();
        $this->actingAs($this->applicantOn(BillingCycle::Quarterly))->get('/account')
            ->assertOk()->assertSee('Pay K250', false);
    }

    public function test_an_expired_member_renewing_in_may_or_june_gets_no_amount_choices(): void
    {
        foreach (['2026-05-20 10:00:00', '2026-06-30 12:00:00'] as $i => $at) {
            Carbon::setTestNow($at);
            Membership::query()->delete();

            $user = User::factory()->create();
            $plan = MembershipPlan::query()->where('billing_cycle', BillingCycle::Annual)->firstOrFail();
            Membership::query()->create([
                // Each person needs their own number now that a number belongs to one person.
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'membership_number' => 'LFS-00032'.$i,
                'status' => MembershipStatus::Expired, 'current_plan_id' => $plan->id, 'approval_status' => 'approved',
                'start_date' => '2025-01-10', 'expiry_date' => '2025-12-31',
            ]);

            $this->actingAs($user)->get('/account')
                ->assertOk()
                ->assertSee('Renew by paying the amount below', false)
                ->assertDontSee('Choose how much to pay now', false)
                ->assertDontSee('K250', false)
                ->assertDontSee('K500 initial payment', false);
        }
    }

    public function test_a_suspended_member_in_may_clears_only_what_they_still_owe_in_one_payment(): void
    {
        // Started in April with K250, still K750 short at the 30 April deadline,
        // so the daily job suspended them on 1 May. Paying the rest is a single
        // charge of exactly the shortfall (not K1,000 again, not another instalment).
        Carbon::setTestNow('2026-05-15 10:00:00');
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->where('billing_cycle', BillingCycle::Annual)->firstOrFail();
        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'membership_number' => '13657',
            'status' => MembershipStatus::Suspended, 'current_plan_id' => $plan->id, 'approval_status' => 'approved',
            'approved_by' => 'system:lenco', 'joined_at' => '2026-04-01', 'start_date' => '2026-04-01', 'expiry_date' => '2026-12-31',
            'grace_period_ends_at' => '2026-04-30',
        ]);
        MembershipPayment::query()->create([
            'membership_id' => $membership->id, 'plan_id' => $plan->id, 'amount' => 1000, 'amount_paid' => 250,
            'currency' => 'ZMW', 'payment_gateway' => 'lenco', 'status' => MembershipPaymentStatus::PartiallyPaid,
        ]);

        $this->assertSame(750.0, $this->chargedWhenPaying($user, 'LFS-REF-TOPUP'));
    }

    public function test_the_unpaid_balance_job_suspends_the_day_after_30_april_and_not_before(): void
    {
        $plan = MembershipPlan::query()->where('billing_cycle', BillingCycle::Quarterly)->firstOrFail();
        $make = function (string $number) use ($plan): Membership {
            $user = User::factory()->create();
            $membership = Membership::query()->create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'membership_number' => $number,
                'status' => MembershipStatus::Active, 'current_plan_id' => $plan->id, 'approval_status' => 'approved',
                'start_date' => '2026-03-01', 'expiry_date' => '2026-12-31', 'grace_period_ends_at' => '2026-04-30',
            ]);
            MembershipPayment::query()->create([
                'membership_id' => $membership->id, 'plan_id' => $plan->id, 'amount' => 1000, 'amount_paid' => 250,
                'currency' => 'ZMW', 'payment_gateway' => 'lenco', 'status' => MembershipPaymentStatus::PartiallyPaid,
            ]);

            return $membership;
        };

        $member = $make('LFS-000701');

        Carbon::setTestNow('2026-04-30 23:30:00');
        $this->artisan('membership:suspend-unpaid')->assertSuccessful();
        $this->assertSame(MembershipStatus::Active, $member->fresh()->status, 'still inside the grace window on 30 April');

        Carbon::setTestNow('2026-05-01 00:30:00');
        $this->artisan('membership:suspend-unpaid')->assertSuccessful();
        $this->assertSame(MembershipStatus::Suspended, $member->fresh()->status, 'suspended from 1 May');
    }
}
