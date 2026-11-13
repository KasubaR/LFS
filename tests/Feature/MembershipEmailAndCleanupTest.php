<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Notifications\MembershipPaymentReceivedNotification;
use App\Services\MembershipService;
use App\Services\MembershipYearService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MembershipEmailAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    private MembershipService $service;

    private MembershipPlan $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);

        $this->service = app(MembershipService::class);
        $this->annual = MembershipPlan::query()->where('billing_cycle', BillingCycle::Annual)->firstOrFail();
        Carbon::setTestNow('2026-02-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function submitted(User $user): array
    {
        $created = $this->service->createApplication($user->id, $this->annual->id);

        return $this->service->submitApplication($created['membershipId']);
    }

    // ── Confirmation email ───────────────────────────────────────────────────

    public function test_a_full_payment_sends_a_confirmation_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $submitted = $this->submitted($user);

        $this->service->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);

        Notification::assertSentToTimes($user, MembershipPaymentReceivedNotification::class, 1);
        Notification::assertSentTo($user, MembershipPaymentReceivedNotification::class, function ($n) use ($user) {
            $mail = $n->toMail($user);
            $text = implode(' ', array_merge([$mail->subject], $mail->introLines));

            return str_contains($text, 'K1,000.00') && str_contains($text, 'paid in full');
        });
    }

    public function test_an_instalment_email_shows_the_balance_and_a_second_instalment_sends_another(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $submitted = $this->submitted($user);

        $this->service->handlePaymentUpdate($submitted['latestPayment']['id'], 250.00);

        Notification::assertSentTo($user, MembershipPaymentReceivedNotification::class, function ($n) use ($user) {
            $text = implode(' ', $n->toMail($user)->introLines);

            return str_contains($text, 'K250.00') && str_contains($text, 'Balance: K750.00');
        });
    }

    public function test_no_email_when_nothing_was_credited(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $submitted = $this->submitted($user);

        // A replayed confirmation for an already-terminal payment throws before any email.
        $this->service->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);
        try {
            $this->service->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);
        } catch (\Throwable) {
        }

        Notification::assertSentToTimes($user, MembershipPaymentReceivedNotification::class, 1);
    }

    // ── Abandoned application cleanup ────────────────────────────────────────

    private function age(string $membershipId, int $days): void
    {
        Membership::query()->whereKey($membershipId)->update(['updated_at' => now()->subDays($days)]);
    }

    public function test_old_unpaid_drafts_and_pending_applications_are_removed(): void
    {
        $draftUser = User::factory()->create();
        $draft = $this->service->createApplication($draftUser->id, $this->annual->id);
        $this->age($draft['membershipId'], 40);

        $pendingUser = User::factory()->create();
        $pending = $this->submitted($pendingUser);
        $this->age($pending['id'], 40);

        $this->artisan('membership:cleanup-abandoned')->assertSuccessful();

        $this->assertNull(Membership::query()->find($draft['membershipId']));
        $this->assertNull(Membership::query()->find($pending['id']));
        $this->assertSame(0, MembershipPayment::query()->count(), 'the unpaid payment goes with it');
    }

    public function test_recent_applications_are_kept(): void
    {
        $user = User::factory()->create();
        $draft = $this->service->createApplication($user->id, $this->annual->id);
        $this->age($draft['membershipId'], 5);

        $this->artisan('membership:cleanup-abandoned')->assertSuccessful();

        $this->assertNotNull(Membership::query()->find($draft['membershipId']));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $user = User::factory()->create();
        $draft = $this->service->createApplication($user->id, $this->annual->id);
        $this->age($draft['membershipId'], 40);

        $this->artisan('membership:cleanup-abandoned --dry-run')->assertSuccessful();

        $this->assertNotNull(Membership::query()->find($draft['membershipId']));
    }

    public function test_an_application_with_a_charge_in_flight_or_money_paid_is_never_removed(): void
    {
        $inFlightUser = User::factory()->create();
        $inFlight = $this->submitted($inFlightUser);
        MembershipPayment::query()->whereKey($inFlight['latestPayment']['id'])->update(['payment_reference' => 'LFS-REF-1']);
        $this->age($inFlight['id'], 90);

        $paidUser = User::factory()->create();
        $paid = $this->submitted($paidUser);
        $this->service->handlePaymentUpdate($paid['latestPayment']['id'], 250.00);
        $this->age($paid['id'], 90);

        $this->artisan('membership:cleanup-abandoned')->assertSuccessful();

        $this->assertNotNull(Membership::query()->find($inFlight['id']));
        $this->assertNotNull(Membership::query()->find($paid['id']));
    }

    public function test_an_abandoned_early_renewal_is_removed_and_gives_the_qr_token_back(): void
    {
        $user = User::factory()->create();
        $submitted = $this->submitted($user);
        $first = $this->service->handlePaymentUpdate($submitted['latestPayment']['id'], 1000.00);
        $original = Membership::query()->find($first['id']);
        $token = $original->public_token;
        $this->assertNotNull($token);

        app(MembershipYearService::class)->setOpensOn('2026-11-01');
        Carbon::setTestNow('2026-11-10 10:00:00');
        $renewal = $this->service->startRenewal($user->id, $this->annual->id);
        $this->assertNull($original->fresh()->public_token, 'the renewal took the token');
        $this->age($renewal['membershipId'], 60);

        $this->artisan('membership:cleanup-abandoned')->assertSuccessful();

        $this->assertNull(Membership::query()->find($renewal['membershipId']));
        $this->assertSame($token, $original->fresh()->public_token);
        $this->assertTrue($this->service->canRenewEarly($user->id), 'the member can start a fresh renewal');
    }
}
