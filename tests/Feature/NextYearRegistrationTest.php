<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\MembershipStatus;
use App\Exceptions\CodeException;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Promotion;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\MembershipYearService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Once an admin sets "next year opens on", anything paid from that date to
 * 31 Dec is for the following membership year.
 */
class NextYearRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private MembershipService $service;

    private MembershipYearService $years;

    private MembershipPlan $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);

        $this->service = app(MembershipService::class);
        $this->years = app(MembershipYearService::class);
        $this->annual = MembershipPlan::query()->where('billing_cycle', BillingCycle::Annual)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payNewMember(User $user): array
    {
        $created = $this->service->createApplication($user->id, $this->annual->id);
        $submitted = $this->service->submitApplication($created['membershipId']);

        return $this->service->handlePaymentUpdate(
            $submitted['latestPayment']['id'],
            (float) $submitted['latestPayment']['amount']
        );
    }

    private function promotion(string $name, int $year, string $appliesTo): void
    {
        Promotion::query()->create([
            'name' => $name, 'plan_id' => $this->annual->id, 'membership_year' => $year,
            'applies_to' => $appliesTo, 'discount_type' => 'percentage', 'discount_value' => 10,
            'starts_at' => '2026-11-01', 'ends_at' => '2026-12-31', 'is_active' => true,
        ]);
    }

    public function test_nothing_changes_before_the_open_date(): void
    {
        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-10-31 10:00:00');

        $active = $this->payNewMember(User::factory()->create());

        $this->assertSame('2026-12-31', $active['expiryDate']);
        $this->assertFalse($this->years->isNextYearOpen());
    }

    public function test_a_new_member_paying_in_the_window_gets_next_year_full_fee_and_a_next_year_number(): void
    {
        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-11-15 10:00:00');

        $active = $this->payNewMember(User::factory()->create());

        $this->assertSame('2027-01-01', $active['startDate']);
        $this->assertSame('2027-12-31', $active['expiryDate']);
        $this->assertSame('2027-04-30', $active['gracePeriodEndsAt']);
        // Full annual fee, not the reduced late-joiner fee, even though it is after 1 July.
        $this->assertSame(1000.00, $active['latestPayment']['amount']);
        $this->assertSame('270001', $active['membershipNumber']);
    }

    public function test_the_window_closes_on_new_year_and_the_normal_year_takes_over(): void
    {
        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2027-01-05 10:00:00');

        $active = $this->payNewMember(User::factory()->create());

        $this->assertSame('2027-01-05', $active['startDate']);
        $this->assertSame('2027-12-31', $active['expiryDate']);
    }

    public function test_an_active_member_can_renew_early_and_the_old_row_is_superseded(): void
    {
        Carbon::setTestNow('2026-02-01 10:00:00');
        $user = User::factory()->create();
        $first = $this->payNewMember($user);
        $this->assertSame('2026-12-31', $first['expiryDate']);

        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-11-10 10:00:00');

        $this->assertTrue($this->service->canRenewEarly($user->id));

        $renewal = $this->service->startRenewal($user->id, $this->annual->id);
        $this->assertSame($first['membershipNumber'], $renewal['membershipNumber']);
        $this->assertFalse($this->service->canRenewEarly($user->id), 'a pending renewal blocks a second one');

        $active = $this->service->handlePaymentUpdate($renewal['latestPayment']['id'], 1000.00);

        $this->assertSame(MembershipStatus::Active, $active['status']);
        $this->assertSame('2027-01-01', $active['startDate']);
        $this->assertSame('2027-12-31', $active['expiryDate']);
        $this->assertSame($first['membershipNumber'], $active['membershipNumber']);
        $this->assertSame(MembershipStatus::Expired, Membership::query()->find($first['id'])->status);
        $this->assertFalse($this->service->canRenewEarly($user->id), 'already renewed for next year');
    }

    public function test_no_early_renewal_before_the_window_opens(): void
    {
        Carbon::setTestNow('2026-02-01 10:00:00');
        $user = User::factory()->create();
        $this->payNewMember($user);

        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->assertFalse($this->service->canRenewEarly($user->id));
        $this->expectException(CodeException::class);
        $this->service->startRenewal($user->id, $this->annual->id);
    }

    public function test_a_member_with_a_balance_owing_cannot_renew_early(): void
    {
        Carbon::setTestNow('2026-02-01 10:00:00');
        $user = User::factory()->create();
        $created = $this->service->createApplication($user->id, $this->annual->id);
        $submitted = $this->service->submitApplication($created['membershipId']);
        // Instalment inside the grace window: active but only partially paid.
        $this->service->handlePaymentUpdate($submitted['latestPayment']['id'], 250.00);

        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-11-10 10:00:00');

        $this->assertFalse($this->service->canRenewEarly($user->id));
    }

    public function test_a_promotion_only_applies_to_its_membership_year(): void
    {
        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-11-15 10:00:00');

        $this->promotion('Early bird 2027', 2027, 'new');
        $active = $this->payNewMember(User::factory()->create());
        $this->assertSame(900.00, $active['latestPayment']['amount']);

        Promotion::query()->delete();
        $this->promotion('Early bird 2026', 2026, 'all');
        $other = $this->payNewMember(User::factory()->create());
        $this->assertSame(1000.00, $other['latestPayment']['amount']);
    }

    public function test_a_renewals_only_promotion_does_not_discount_new_members(): void
    {
        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-11-15 10:00:00');

        $this->promotion('Loyalty', 2027, 'renewals');

        $active = $this->payNewMember(User::factory()->create());
        $this->assertSame(1000.00, $active['latestPayment']['amount']);
    }

    public function test_year_is_taken_in_lusaka_time(): void
    {
        $this->years->setOpensOn('2026-11-01');

        // 31 Oct 23:30 UTC is already 1 Nov 01:30 in Lusaka, so the window is open.
        $this->assertSame(2027, $this->years->yearFor(Carbon::parse('2026-10-31 23:30:00', 'UTC')));
        $this->assertSame(2026, $this->years->yearFor(Carbon::parse('2026-10-31 21:30:00', 'UTC')));
    }

    public function test_an_unpaid_application_is_priced_again_when_the_year_rolls_over(): void
    {
        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-10-20 10:00:00');

        $user = User::factory()->create();
        $created = $this->service->createApplication($user->id, $this->annual->id);
        $submitted = $this->service->submitApplication($created['membershipId']);
        $this->assertSame(500.00, $submitted['latestPayment']['amount'], 'late-joiner rate after 1 July');

        // Still the same year: the price stays put.
        Carbon::setTestNow('2026-10-30 10:00:00');
        $this->service->repriceIfYearChanged($created['membershipId'], false);
        $this->assertSame(500.00, $this->latestAmount($created['membershipId']));

        // The window opens: the member would get 2027, so the full fee applies.
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->service->repriceIfYearChanged($created['membershipId'], false);
        $this->assertSame(1000.00, $this->latestAmount($created['membershipId']));
    }

    public function test_new_year_reprices_an_application_left_over_from_december(): void
    {
        Carbon::setTestNow('2026-12-20 10:00:00');
        $user = User::factory()->create();
        $created = $this->service->createApplication($user->id, $this->annual->id);
        $this->service->submitApplication($created['membershipId']);
        $this->assertSame(500.00, $this->latestAmount($created['membershipId']));

        Carbon::setTestNow('2027-01-10 10:00:00');
        $this->service->repriceIfYearChanged($created['membershipId'], false);
        $this->assertSame(1000.00, $this->latestAmount($created['membershipId']));
    }

    public function test_pricing_helper_knows_whether_the_payer_is_renewing(): void
    {
        $this->years->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->promotion('Loyalty', 2027, 'renewals');

        $this->assertSame(1000.00, $this->service->currentAnnualPricing()['amount']);
        $this->assertSame(900.00, $this->service->currentAnnualPricing(true)['amount']);
    }

    private function latestAmount(string $membershipId): float
    {
        return (float) \App\Models\MembershipPayment::query()
            ->where('membership_id', $membershipId)->latest('id')->value('amount');
    }
}
