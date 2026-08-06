<?php

namespace Tests\Feature\Auth;

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
use Illuminate\Support\Str;
use Tests\TestCase;

class MembershipPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    private function createDraftMembership(User $user): Membership
    {
        $plan = MembershipPlan::query()->first();

        return Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000001',
            'status' => MembershipStatus::Draft,
            'current_plan_id' => $plan->id,
            'approval_status' => 'pending',
        ]);
    }

    public function test_initiate_submits_draft_and_stores_lenco_reference_on_pending_payment(): void
    {
        $user = User::factory()->create();
        $membership = $this->createDraftMembership($user);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-REF-1');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 'txn-1',
                'lencoReference' => 'lenco-ref-1',
                'reference' => 'LFS-REF-1',
                'status' => 'pay-offline',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'rawResponse' => [],
            ]);
        });

        $response = $this->actingAs($user)->postJson('/account/payment/initiate', [
            'provider' => 'mtn',
            'phone' => '+260971234567',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'reference' => 'LFS-REF-1']);

        $this->assertSame(MembershipStatus::PendingPayment, $membership->fresh()->status);

        $payment = MembershipPayment::query()->where('membership_id', $membership->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('LFS-REF-1', $payment->payment_reference);
        $this->assertSame('lenco-ref-1', $payment->lenco_reference);
        $this->assertSame('pending', $payment->status->value ?? $payment->status);
    }

    public function test_verify_activates_membership_when_lenco_reports_completed(): void
    {
        $user = User::factory()->create();
        $membership = $this->createDraftMembership($user);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-REF-1');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 'txn-1',
                'lencoReference' => 'lenco-ref-1',
                'reference' => 'LFS-REF-1',
                'status' => 'pay-offline',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'rawResponse' => [],
            ]);
            $mock->shouldReceive('verifyPayment')->once()->with('LFS-REF-1', true)->andReturn([
                'status' => 'successful',
                'internalStatus' => 'completed',
                'rawResponse' => [],
            ]);
        });

        $this->actingAs($user)->postJson('/account/payment/initiate', [
            'provider' => 'mtn',
            'phone' => '+260971234567',
        ])->assertOk();

        $response = $this->actingAs($user)->getJson('/account/payment/verify?reference=LFS-REF-1');

        $response->assertOk();
        $response->assertJson(['ok' => true, 'status' => 'completed']);

        $membership->refresh();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame('system:lenco', $membership->approved_by);
    }

    public function test_verify_rejects_payment_belonging_to_another_user(): void
    {
        $owner = User::factory()->create();
        $membership = $this->createDraftMembership($owner);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-REF-1');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 'txn-1',
                'lencoReference' => 'lenco-ref-1',
                'reference' => 'LFS-REF-1',
                'status' => 'pay-offline',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'rawResponse' => [],
            ]);
        });

        $this->actingAs($owner)->postJson('/account/payment/initiate', [
            'provider' => 'mtn',
            'phone' => '+260971234567',
        ])->assertOk();

        $intruder = User::factory()->create();
        $this->createDraftMembership($intruder);

        $response = $this->actingAs($intruder)->getJson('/account/payment/verify?reference=LFS-REF-1');

        $response->assertStatus(404);
        $this->assertSame(MembershipStatus::PendingPayment, $membership->fresh()->status);
    }

    public function test_webhook_activates_membership_with_valid_signature(): void
    {
        $user = User::factory()->create();
        $membership = $this->createDraftMembership($user);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-REF-2');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 'txn-2',
                'lencoReference' => 'lenco-ref-2',
                'reference' => 'LFS-REF-2',
                'status' => 'pay-offline',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'rawResponse' => [],
            ]);
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturn(true);
            $mock->shouldReceive('parseWebhookPayload')->once()->andReturn([
                'reference' => 'LFS-REF-2',
                'status' => 'successful',
            ]);
            $mock->shouldReceive('mapLencoStatus')->once()->with('successful')->andReturn('completed');
        });

        $this->actingAs($user)->postJson('/account/payment/initiate', [
            'provider' => 'mtn',
            'phone' => '+260971234567',
        ])->assertOk();

        $response = $this->postJson('/account/payment/webhook', [
            'data' => ['reference' => 'LFS-REF-2', 'status' => 'successful'],
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $membership->refresh();
        $this->assertSame(MembershipStatus::Active, $membership->status);

        $payment = MembershipPayment::query()->where('membership_id', $membership->id)->first();
        $this->assertTrue((bool) $payment->webhook_received);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturn(false);
        });

        $response = $this->postJson('/account/payment/webhook', [
            'data' => ['reference' => 'LFS-REF-3', 'status' => 'successful'],
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => false, 'message' => 'Invalid signature']);
    }

    public function test_renewal_creates_pending_membership_from_expired(): void
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->first();

        Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000002',
            'status' => MembershipStatus::Expired,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'joined_at' => now()->subYear(),
        ]);

        $response = $this->actingAs($user)->post('/account/renew', [
            'plan_id' => $plan->id,
        ]);

        $response->assertRedirect('/account');
        $response->assertSessionDoesntHaveErrors();

        $renewal = Membership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::PendingPayment)
            ->first();

        $this->assertNotNull($renewal, 'Expected a new PendingPayment membership row to be created.');
        $this->assertSame('LFS-000002', $renewal->membership_number);

        // The new PendingPayment row can share the same second-precision created_at
        // as the Expired row it supersedes — /account must still surface the
        // actionable one, not fall back to whichever row the DB returns first.
        $accountResponse = $this->actingAs($user)->get('/account');
        $accountResponse->assertOk();
        $accountResponse->assertSee('Pay with Mobile Money', false);
    }

    public function test_reinitiate_while_gateway_session_active_returns_conflict(): void
    {
        $user = User::factory()->create();
        $membership = $this->createDraftMembership($user);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-REF-1');
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 'txn-1',
                'lencoReference' => 'lenco-ref-1',
                'reference' => 'LFS-REF-1',
                'status' => 'pay-offline',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'rawResponse' => [],
            ]);
        });

        $this->actingAs($user)->postJson('/account/payment/initiate', [
            'provider' => 'mtn',
            'phone' => '+260971234567',
        ])->assertOk();

        $this->actingAs($user)->postJson('/account/payment/initiate', [
            'provider' => 'mtn',
            'phone' => '+260971234567',
        ])->assertStatus(409);
    }

    private function createUnderpaidActiveMembership(User $user): Membership
    {
        $plan = MembershipPlan::query()->where('billing_cycle', \App\Enums\BillingCycle::Annual)->first();

        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => '13657',
            'status' => MembershipStatus::Active,
            'current_plan_id' => $plan->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:import',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ]);

        MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => 1000,
            'amount_paid' => 900,
            'currency' => 'ZMW',
            'payment_gateway' => 'import',
            'status' => MembershipPaymentStatus::PartiallyPaid,
        ]);

        return $membership;
    }

    public function test_balance_top_up_charges_only_the_shortfall_and_clears_the_balance(): void
    {
        $user = User::factory()->create();
        $membership = $this->createUnderpaidActiveMembership($user);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturn('LFS-REF-TOPUP');
            $mock->shouldReceive('initiateMobileMoneyPayment')
                ->once()
                ->withArgs(fn ($orderData) => (float) $orderData['totals']['total'] === 100.0)
                ->andReturn([
                    'transactionId' => 'txn-topup',
                    'lencoReference' => 'lenco-topup',
                    'reference' => 'LFS-REF-TOPUP',
                    'status' => 'pay-offline',
                    'paymentInstructions' => 'Approve on your phone',
                    'paymentUrl' => null,
                    'expiresAt' => null,
                    'rawResponse' => [],
                ]);
            $mock->shouldReceive('verifyPayment')->once()->with('LFS-REF-TOPUP', true)->andReturn([
                'status' => 'successful',
                'internalStatus' => 'completed',
                'rawResponse' => [],
            ]);
        });

        // Still owed — middleware forces /account/balance.
        $this->actingAs($user)->get('/account')->assertRedirect('/account/balance');

        $this->actingAs($user)->postJson('/account/payment/initiate', [
            'provider' => 'mtn',
            'phone' => '+260971234567',
        ])->assertOk()->assertJson(['ok' => true, 'reference' => 'LFS-REF-TOPUP']);

        $this->actingAs($user)->getJson('/account/payment/verify?reference=LFS-REF-TOPUP')
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'completed']);

        $payment = MembershipPayment::query()->where('membership_id', $membership->id)->first();
        $this->assertSame('1000.00', $payment->amount);
        $this->assertSame('1000.00', $payment->amount_paid);
        $this->assertSame('paid', $payment->status);

        // Balance cleared — no longer redirected away from the dashboard.
        $this->actingAs($user)->get('/account')->assertOk();
    }

    public function test_failed_payment_can_be_retried_with_new_row(): void
    {
        $user = User::factory()->create();
        $membership = $this->createDraftMembership($user);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('generateReference')->andReturnUsing(fn ($seed) => 'LFS-REF-'.substr(md5((string) $seed.microtime()), 0, 8));
            $call = 0;
            $mock->shouldReceive('initiateMobileMoneyPayment')->twice()->andReturnUsing(function () use (&$call) {
                $call++;
                $ref = $call === 1 ? 'LFS-REF-FAIL' : 'LFS-REF-RETRY';

                return [
                    'transactionId' => 'txn-'.$call,
                    'lencoReference' => 'lenco-'.$call,
                    'reference' => $ref,
                    'status' => 'pay-offline',
                    'paymentInstructions' => 'Approve on your phone',
                    'paymentUrl' => null,
                    'expiresAt' => null,
                    'rawResponse' => [],
                ];
            });
            $mock->shouldReceive('verifyPayment')->once()->with('LFS-REF-FAIL', true)->andReturn([
                'status' => 'declined',
                'internalStatus' => 'failed',
                'rawResponse' => [],
            ]);
        });

        $this->actingAs($user)->postJson('/account/payment/initiate', [
            'provider' => 'mtn',
            'phone' => '+260971234567',
        ])->assertOk();

        $this->actingAs($user)->getJson('/account/payment/verify?reference=LFS-REF-FAIL')
            ->assertOk()
            ->assertJson(['status' => 'failed']);

        $failed = MembershipPayment::query()->where('membership_id', $membership->id)->latest('id')->first();
        $this->assertSame('failed', $failed->status);

        $this->actingAs($user)->postJson('/account/payment/initiate', [
            'provider' => 'mtn',
            'phone' => '+260971234567',
        ])->assertOk()->assertJson(['reference' => 'LFS-REF-RETRY']);

        $this->assertSame(2, MembershipPayment::query()->where('membership_id', $membership->id)->count());
        $this->assertSame(MembershipStatus::PendingPayment, $membership->fresh()->status);
    }
}
