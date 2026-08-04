<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\User;
use App\Services\MembershipPaymentService;
use App\Services\PaymentService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_order_payment_update_status_cannot_double_apply(): void
    {
        $payment = Payment::query()->create([
            'order_number' => 'LFS-20260101-RACE1',
            'payment_method' => 'mobile_money',
            'amount' => 100,
            'currency' => 'ZMW',
            'status' => 'pending',
            'customer_name' => 'Race Test',
            'customer_email' => 'race@example.com',
            'transaction_id' => 'LFS-RACE-REF-1',
        ]);

        $service = app(PaymentService::class);

        // First writer (e.g. the webhook) wins.
        $first = $service->updateStatus($payment->id, 'completed', ['completedAt' => now()->toDateTimeString()]);
        $this->assertTrue($first);
        $this->assertSame('completed', $payment->fresh()->status);

        // A second, concurrent writer (e.g. the reconciliation poller re-verifying
        // the same payment) must not be able to overwrite the terminal status.
        $second = $service->updateStatus($payment->id, 'failed', ['failureReason' => 'stale duplicate']);
        $this->assertFalse($second);
        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_membership_payment_record_amount_paid_cannot_double_apply(): void
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->first();

        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000040',
            'status' => MembershipStatus::PendingPayment,
            'current_plan_id' => $plan->id,
            'approval_status' => 'pending',
        ]);

        $payment = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'amount_paid' => 0,
            'currency' => 'ZMW',
            'payment_reference' => 'LFS-RACE-REF-2',
            'payment_gateway' => 'lenco',
            'status' => 'pending',
        ]);

        $service = app(MembershipPaymentService::class);

        $first = $service->recordAmountPaid($payment->id, (float) $plan->price, ['paidAt' => now()]);
        $this->assertNotNull($first);
        $this->assertSame('paid', $payment->fresh()->status->value ?? $payment->fresh()->status);

        // Second concurrent call (e.g. a duplicate webhook delivery) must be a no-op.
        $second = $service->recordAmountPaid($payment->id, (float) $plan->price, ['paidAt' => now()]);
        $this->assertNull($second);
    }

    public function test_handle_payment_update_activates_when_payment_already_paid(): void
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->first();

        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => null,
            'status' => MembershipStatus::PendingPayment,
            'current_plan_id' => $plan->id,
            'approval_status' => 'pending',
        ]);

        $payment = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'amount_paid' => $plan->price,
            'currency' => 'ZMW',
            'payment_reference' => 'LFS-RACE-REF-3',
            'payment_gateway' => 'lenco',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $result = app(\App\Services\MembershipService::class)->handlePaymentUpdate(
            $payment->id,
            (float) $plan->price
        );

        $this->assertSame(MembershipStatus::Active, $result['status']);
        $this->assertNotNull($result['membershipNumber']);
    }
}
