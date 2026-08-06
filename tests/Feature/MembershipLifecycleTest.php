<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\BillingCycle;
use App\Enums\MembershipHistoryEvent;
use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\CodeException;
use App\Models\Membership;
use App\Models\MembershipHistory;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\Promotion;
use App\Models\Satellite;
use App\Models\User;
use App\Services\MembershipService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private MembershipService $membershipService;

    private User $user;

    private MembershipPlan $annualPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);

        $this->membershipService = app(MembershipService::class);

        $satellite = Satellite::query()->where('slug', 'woodies')->first();

        $this->user = User::factory()->create([
            'last_name' => 'Runner',
            'other_names' => 'Jane',
            'email' => 'jane@example.com',
            'phone' => '0977000000',
            'satellite_id' => $satellite->id,
        ]);

        $this->annualPlan = MembershipPlan::query()
            ->where('billing_cycle', BillingCycle::Annual)
            ->first();
    }

    public function test_new_signup_flow_from_draft_to_active_on_payment(): void
    {
        // Freeze inside the normal Jan-Apr renewal window — the real "now" in
        // this sandbox is already past the 1 June late-joiner cutoff, which
        // would otherwise halve the annual fee this test expects.
        Carbon::setTestNow('2026-01-15 10:00:00');

        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);

        $membership = Membership::query()->find($created['membershipId']);
        $this->assertSame(MembershipStatus::Draft, $membership->status);
        // No number is allocated until payment is confirmed.
        $this->assertNull($created['membershipNumber']);
        $this->assertNull($membership->membership_number);

        $submitted = $this->membershipService->submitApplication($created['membershipId']);
        $this->assertSame(MembershipStatus::PendingPayment, $submitted['status']);
        $this->assertSame(1000.00, $submitted['latestPayment']['amount']);
        $this->assertNull($submitted['membershipNumber']);

        $active = $this->membershipService->handlePaymentUpdate(
            $submitted['latestPayment']['id'],
            1000.00
        );

        $this->assertSame(MembershipStatus::Active, $active['status']);
        $this->assertStringStartsWith('LFS-', $active['membershipNumber']);
        $this->assertSame(ApprovalStatus::Approved, $active['approvalStatus']);
        $this->assertSame('system:lenco', $active['approvedBy']);
        $this->assertSame('2026-01-15', $active['startDate']);
        $this->assertSame('2026-12-31', $active['expiryDate']);
        $this->assertSame('2026-12-31', $active['renewalDueDate']);
        $this->assertSame('2026-04-30', $active['gracePeriodEndsAt']);
        $this->assertSame('2026-01-15', $active['latestPayment']['coversFrom']);
        $this->assertSame('2026-12-31', $active['latestPayment']['coversTo']);

        Carbon::setTestNow();
    }

    public function test_partial_payment_during_grace_period_still_activates_the_membership(): void
    {
        // Paying only an installment (not the full annual fee) during the
        // Jan-Apr grace window still activates the membership with full
        // access — the balance is just owed, not a blocker. See
        // MembershipService::findOutstandingBalancePayment() /
        // findGracePeriodBalanceReminder() and SuspendUnpaidMembershipsCommand.
        Carbon::setTestNow('2026-01-15 10:00:00');

        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);

        $updated = $this->membershipService->handlePaymentUpdate(
            $submitted['latestPayment']['id'],
            500.00
        );

        $this->assertSame(MembershipStatus::Active, $updated['status']);
        $this->assertSame(MembershipPaymentStatus::PartiallyPaid, $updated['latestPayment']['status']);
        $this->assertSame('2026-04-30', $updated['gracePeriodEndsAt']);
        $this->assertNotNull($updated['membershipNumber']);

        Carbon::setTestNow();
    }

    public function test_plan_duration_date_math_for_semi_annual_and_quarterly(): void
    {
        Carbon::setTestNow('2026-06-01');

        $semiAnnual = MembershipPlan::query()->where('billing_cycle', BillingCycle::SemiAnnual)->first();
        $quarterly = MembershipPlan::query()->where('billing_cycle', BillingCycle::Quarterly)->first();

        $semiDates = $this->membershipService->computePeriodDates(now(), (int) $semiAnnual->duration_months);
        $quarterDates = $this->membershipService->computePeriodDates(now(), (int) $quarterly->duration_months);

        $this->assertSame('2026-06-01', $semiDates['startDate']);
        $this->assertSame('2026-11-30', $semiDates['expiryDate']);
        $this->assertSame('2026-06-01', $quarterDates['startDate']);
        $this->assertSame('2026-08-31', $quarterDates['expiryDate']);

        Carbon::setTestNow();
    }

    public function test_renewal_creates_new_membership_row_and_preserves_number(): void
    {
        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);
        $activated = $this->membershipService->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);

        $firstMembershipId = $created['membershipId'];
        $membershipNumber = $activated['membershipNumber'];
        $this->assertStringStartsWith('LFS-', $membershipNumber);

        $this->membershipService->expire($firstMembershipId);

        $renewal = $this->membershipService->startRenewal($this->user->id, $this->annualPlan->id);
        $this->assertSame(MembershipStatus::PendingPayment, $renewal['status']);
        $this->assertSame($membershipNumber, $renewal['membershipNumber']);
        $this->assertNotSame($firstMembershipId, $renewal['membershipId']);

        $reactivated = $this->membershipService->handlePaymentUpdate(
            $renewal['latestPayment']['id'],
            1000.00
        );

        $this->assertSame(MembershipStatus::Active, $reactivated['status']);
        $this->assertSame($membershipNumber, $reactivated['membershipNumber']);

        $this->assertSame(MembershipStatus::Expired, Membership::query()->find($firstMembershipId)->status);
        $this->assertSame(MembershipStatus::Active, Membership::query()->find($renewal['membershipId'])->status);
        $this->assertCount(2, Membership::query()->where('user_id', $this->user->id)->get());
    }

    public function test_membership_history_records_key_events(): void
    {
        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);
        $this->membershipService->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);

        $events = MembershipHistory::query()
            ->where('membership_id', $created['membershipId'])
            ->orderBy('id')
            ->pluck('event')
            ->all();

        $this->assertContains(MembershipHistoryEvent::Created, $events);
        $this->assertContains(MembershipHistoryEvent::Submitted, $events);
        $this->assertContains(MembershipHistoryEvent::Activated, $events);
        $this->assertContains(MembershipHistoryEvent::PaymentReceived, $events);

        $activeHistory = MembershipHistory::query()
            ->where('membership_id', $created['membershipId'])
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($activeHistory);
        $this->assertSame(MembershipHistoryEvent::Activated, $activeHistory->event);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);

        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('Cannot transition membership');

        $this->membershipService->expire($created['membershipId']);
    }

    public function test_admin_display_status_mapping_and_list_query(): void
    {
        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);

        $pendingMembers = $this->membershipService->getMembersForAdmin(['filterStatus' => 'pending']);
        $this->assertCount(1, $pendingMembers);
        $this->assertSame('pending', $pendingMembers[0]['status']);
        $this->assertSame('Jane', $pendingMembers[0]['firstName']);
        $this->assertSame('Woodies', $pendingMembers[0]['satellite']);

        $submitted = $this->membershipService->submitApplication($created['membershipId']);
        $activated = $this->membershipService->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);

        $activeMembers = $this->membershipService->getMembersForAdmin(['filterStatus' => 'active']);
        $this->assertCount(1, $activeMembers);
        $this->assertSame('active', $activeMembers[0]['status']);
        $this->assertSame($activated['membershipNumber'], $activeMembers[0]['membershipNumber']);
    }

    public function test_paid_pending_membership_recovers_activation_on_retry(): void
    {
        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);
        $paymentId = $submitted['latestPayment']['id'];

        app(\App\Services\MembershipPaymentService::class)->recordAmountPaid($paymentId, 1000.00, [
            'paidAt' => now(),
        ]);

        $this->assertSame(MembershipStatus::PendingPayment, Membership::query()->find($created['membershipId'])->status);
        $this->assertSame(MembershipPaymentStatus::Paid, MembershipPayment::query()->find($paymentId)->status);

        $recovered = $this->membershipService->handlePaymentUpdate($paymentId, 1000.00);

        $this->assertSame(MembershipStatus::Active, $recovered['status']);
        $this->assertNotNull($recovered['membershipNumber']);
    }

    public function test_plan_change_blocked_after_payment_initiated(): void
    {
        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);

        \App\Models\MembershipPayment::query()->whereKey($submitted['latestPayment']['id'])->update([
            'payment_reference' => 'LFS-LOCKED-REF',
        ]);

        $quarterly = MembershipPlan::query()->where('billing_cycle', BillingCycle::Quarterly)->first();

        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('Cannot change plan after payment has been initiated');

        $this->membershipService->changePlan($this->user->id, $quarterly->id);
    }

    public function test_renewal_transfers_public_token(): void
    {
        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);
        $this->membershipService->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);

        $first = Membership::query()->find($created['membershipId']);
        $token = $first->public_token;
        $this->assertNotNull($token);

        $this->membershipService->expire($first->id);

        $renewal = $this->membershipService->startRenewal($this->user->id, $this->annualPlan->id);
        $renewalRow = Membership::query()->find($renewal['membershipId']);

        $this->assertNull($first->fresh()->public_token);
        $this->assertSame($token, $renewalRow->public_token);
    }

    public function test_membership_numbers_are_unique_across_activations(): void
    {
        $userB = User::factory()->create(['satellite_id' => $this->user->satellite_id]);

        $a = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $aPay = $this->membershipService->submitApplication($a['membershipId']);
        $activeA = $this->membershipService->handlePaymentUpdate($aPay['latestPayment']['id'], 1000.00);

        $b = $this->membershipService->createApplication($userB->id, $this->annualPlan->id);
        $bPay = $this->membershipService->submitApplication($b['membershipId']);
        $activeB = $this->membershipService->handlePaymentUpdate($bPay['latestPayment']['id'], 1000.00);

        $this->assertNotSame($activeA['membershipNumber'], $activeB['membershipNumber']);
    }

    public function test_receipt_number_is_assigned_on_payment_and_is_unique_and_distinct_from_membership_number(): void
    {
        $userB = User::factory()->create(['satellite_id' => $this->user->satellite_id]);

        $a = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $aPay = $this->membershipService->submitApplication($a['membershipId']);
        $activeA = $this->membershipService->handlePaymentUpdate($aPay['latestPayment']['id'], 1000.00);

        $b = $this->membershipService->createApplication($userB->id, $this->annualPlan->id);
        $bPay = $this->membershipService->submitApplication($b['membershipId']);
        $activeB = $this->membershipService->handlePaymentUpdate($bPay['latestPayment']['id'], 1000.00);

        $paymentA = MembershipPayment::query()->find($aPay['latestPayment']['id']);
        $paymentB = MembershipPayment::query()->find($bPay['latestPayment']['id']);

        $this->assertNotNull($paymentA->receipt_number);
        $this->assertNotNull($paymentB->receipt_number);
        $this->assertNotSame($paymentA->receipt_number, $paymentB->receipt_number);

        // Distinct from both the membership number and the raw Lenco-style
        // payment reference — a receipt number is its own dedicated id.
        $this->assertNotSame($activeA['membershipNumber'], $paymentA->receipt_number);
        $this->assertNotSame($paymentA->payment_reference, $paymentA->receipt_number);
        $this->assertMatchesRegularExpression('/^LFS-RCT-\d{6}$/', $paymentA->receipt_number);
        $this->assertMatchesRegularExpression('/^LFS-RCT-\d{6}$/', $paymentB->receipt_number);
    }

    public function test_active_promotion_discounts_the_payment_created_on_submission(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        Promotion::query()->create([
            'name' => 'Early Bird Annual',
            'plan_id' => $this->annualPlan->id,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);

        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);

        $this->assertSame(900.00, $submitted['latestPayment']['amount']);
        $this->assertSame(100.00, (float) $submitted['latestPayment']['discountAmount']);
        $this->assertNotNull($submitted['latestPayment']['promotionId']);

        Carbon::setTestNow();
    }

    public function test_late_joiner_registering_after_june_gets_reduced_fee_and_no_grace_period(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);

        $this->assertSame(500.00, $submitted['latestPayment']['amount']);

        $active = $this->membershipService->handlePaymentUpdate($submitted['latestPayment']['id'], 500.00);

        $this->assertSame(MembershipStatus::Active, $active['status']);
        $this->assertSame('2026-07-10', $active['startDate']);
        $this->assertSame('2026-12-31', $active['expiryDate']);
        // Past the grace window entirely — no partial-payment allowance.
        $this->assertNull($active['gracePeriodEndsAt']);

        Carbon::setTestNow();
    }

    public function test_registration_after_grace_window_but_before_late_joiner_cutoff_pays_full_with_no_grace(): void
    {
        // 15 May: past the 30 April grace deadline, but before the 1 June
        // late-joiner cutoff — full price, no partial-payment allowance.
        Carbon::setTestNow('2026-05-15 10:00:00');

        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);

        $this->assertSame(1000.00, $submitted['latestPayment']['amount']);

        $active = $this->membershipService->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);

        $this->assertNull($active['gracePeriodEndsAt']);

        Carbon::setTestNow();
    }

    public function test_registration_on_grace_deadline_itself_still_gets_the_grace_period(): void
    {
        // 30 April inclusive — the boundary should still count as "within".
        Carbon::setTestNow('2026-04-30 23:00:00');

        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);
        $active = $this->membershipService->handlePaymentUpdate($submitted['latestPayment']['id'], 300.00);

        // A partial payment already activates the membership within the
        // grace window — see test_partial_payment_during_grace_period_*.
        $this->assertSame(MembershipStatus::Active, $active['status']);
        $this->assertSame(MembershipPaymentStatus::PartiallyPaid, $active['latestPayment']['status']);
        $this->assertSame('2026-04-30', $active['gracePeriodEndsAt']);

        $activated = $this->membershipService->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);
        $this->assertSame(MembershipStatus::Active, $activated['status']);
        $this->assertSame(MembershipPaymentStatus::Paid, $activated['latestPayment']['status']);
        $this->assertSame('2026-04-30', $activated['gracePeriodEndsAt']);

        Carbon::setTestNow();
    }
}
