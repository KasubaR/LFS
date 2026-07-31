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
use App\Models\MembershipPlan;
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
            'name' => 'Jane Runner',
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

        Carbon::setTestNow('2026-01-15 10:00:00');

        $active = $this->membershipService->handlePaymentUpdate(
            $submitted['latestPayment']['id'],
            1000.00
        );

        $this->assertSame(MembershipStatus::Active, $active['status']);
        $this->assertStringStartsWith('LFS-', $active['membershipNumber']);
        $this->assertSame(ApprovalStatus::Approved, $active['approvalStatus']);
        $this->assertSame('system:lenco', $active['approvedBy']);
        $this->assertSame('2026-01-15', $active['startDate']);
        $this->assertSame('2027-01-14', $active['expiryDate']);
        $this->assertSame('2027-01-14', $active['renewalDueDate']);
        $this->assertSame('2026-01-15', $active['latestPayment']['coversFrom']);
        $this->assertSame('2027-01-14', $active['latestPayment']['coversTo']);

        Carbon::setTestNow();
    }

    public function test_partial_payment_keeps_membership_in_pending_payment(): void
    {
        $created = $this->membershipService->createApplication($this->user->id, $this->annualPlan->id);
        $submitted = $this->membershipService->submitApplication($created['membershipId']);

        $updated = $this->membershipService->handlePaymentUpdate(
            $submitted['latestPayment']['id'],
            500.00
        );

        $this->assertSame(MembershipStatus::PendingPayment, $updated['status']);
        $this->assertSame(MembershipPaymentStatus::PartiallyPaid, $updated['latestPayment']['status']);
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
}
