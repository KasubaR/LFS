<?php

namespace Tests\Feature\Console;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\LencoService;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PollPendingPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    public function test_command_resolves_stuck_order_and_membership_payments(): void
    {
        $order = Order::query()->create([
            'order_number' => 'LFS-20260101-ABCDE',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '0971234567',
            'subtotal' => 250,
            'total' => 250,
            'status' => 'pending_payment',
        ]);

        $orderPayment = Payment::query()->create([
            'order_number' => $order->order_number,
            'payment_method' => 'mobile_money',
            'amount' => 250,
            'currency' => 'ZMW',
            'status' => 'pending',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'transaction_id' => 'LFS-ORDER-REF-1',
        ]);
        $orderPayment->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $user = User::factory()->create();
        $plan = MembershipPlan::query()->first();
        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000030',
            'status' => MembershipStatus::PendingPayment,
            'current_plan_id' => $plan->id,
            'approval_status' => 'pending',
        ]);

        $membershipPayment = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'amount_paid' => 0,
            // Set by recordGatewayInitiation() for any real in-flight charge —
            // required for the poller to credit it (see resolveConfirmedCharge()).
            'pending_charge_amount' => $plan->price,
            'currency' => 'ZMW',
            'payment_reference' => 'LFS-MEMBER-REF-1',
            'payment_gateway' => 'lenco',
            'status' => 'pending',
        ]);
        $membershipPayment->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')
                ->once()
                ->with('LFS-ORDER-REF-1', true)
                ->andReturn([
                    'status' => 'successful',
                    'internalStatus' => 'completed',
                    'rawResponse' => [],
                ]);
            $mock->shouldReceive('verifyPayment')
                ->once()
                ->with('LFS-MEMBER-REF-1', true)
                ->andReturn([
                    'status' => 'successful',
                    'internalStatus' => 'completed',
                    'rawResponse' => [],
                ]);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame('completed', $orderPayment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);

        $this->assertSame(MembershipStatus::Active, $membership->fresh()->status);
        $this->assertSame('system:lenco', $membership->fresh()->approved_by);
        $this->assertSame('paid', $membershipPayment->fresh()->status);
    }

    public function test_command_recovers_a_stuck_partial_membership_payment(): void
    {
        // Partial-payment counterpart to test_command_resolves_stuck_order_and_membership_payments:
        // the first installment's own webhook was missed, and it's a
        // genuine partial charge (pending_charge_amount < amount) rather
        // than one that happens to clear the balance in full — the poller
        // must activate the membership as PartiallyPaid, not Paid, and must
        // stamp a real grace_period_ends_at (see
        // MembershipService::handlePaymentUpdate()'s grace-window check).
        Carbon::setTestNow('2026-02-01 10:00:00');

        $user = User::factory()->create();
        $quarterly = MembershipPlan::query()->where('billing_cycle', \App\Enums\BillingCycle::Quarterly)->first();
        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000031',
            'status' => MembershipStatus::PendingPayment,
            'current_plan_id' => $quarterly->id,
            'approval_status' => 'pending',
        ]);

        $payment = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $quarterly->id,
            'amount' => 1000,
            'amount_paid' => 0,
            'pending_charge_amount' => 250,
            'currency' => 'ZMW',
            'payment_reference' => 'LFS-MEMBER-PARTIAL-1',
            'payment_gateway' => 'lenco',
            'status' => 'pending',
        ]);
        $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')
                ->once()
                ->with('LFS-MEMBER-PARTIAL-1', true)
                ->andReturn([
                    'status' => 'successful',
                    'internalStatus' => 'completed',
                    'rawResponse' => [],
                ]);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $membership->refresh();
        $payment->refresh();

        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertNotNull($membership->grace_period_ends_at);
        $this->assertSame('250.00', $payment->amount_paid);
        $this->assertSame('partially_paid', $payment->status);
        $this->assertNull($payment->payment_reference, 'reference must be cleared or a follow-up top-up would be blocked');

        Carbon::setTestNow();
    }

    public function test_command_recovers_a_stuck_second_installment_top_up(): void
    {
        // Continuation of the scenario above: the membership is already
        // Active + PartiallyPaid from a first installment, and a later
        // top-up attempt's own webhook is the one that got missed this
        // time. The poller must credit it on top of what's already paid
        // (not replace it) and bring the payment to Paid.
        Carbon::setTestNow('2026-02-15 10:00:00');

        $user = User::factory()->create();
        $quarterly = MembershipPlan::query()->where('billing_cycle', \App\Enums\BillingCycle::Quarterly)->first();
        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000032',
            'status' => MembershipStatus::Active,
            'current_plan_id' => $quarterly->id,
            'approval_status' => 'approved',
            'approved_by' => 'system:lenco',
            'joined_at' => now(),
            'start_date' => now()->toDateString(),
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'grace_period_ends_at' => now()->addMonths(2)->toDateString(),
        ]);

        $payment = MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $quarterly->id,
            'amount' => 1000,
            'amount_paid' => 250,
            'pending_charge_amount' => 750,
            'currency' => 'ZMW',
            'payment_reference' => 'LFS-MEMBER-TOPUP-1',
            'payment_gateway' => 'lenco',
            'status' => 'partially_paid',
        ]);
        $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')
                ->once()
                ->with('LFS-MEMBER-TOPUP-1', true)
                ->andReturn([
                    'status' => 'successful',
                    'internalStatus' => 'completed',
                    'rawResponse' => [],
                ]);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $membership->refresh();
        $payment->refresh();

        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame('1000.00', $payment->amount_paid);
        $this->assertSame('paid', $payment->status);

        Carbon::setTestNow();
    }

    private function makeProduct(int $stock): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Jersey',
            'slug' => 'test-jersey-'.Str::random(8),
            'price' => 100,
            'category' => 'jerseys',
            'gender' => 'unisex',
            'sizes' => [['size' => 'M', 'stock' => $stock]],
            'total_stock' => $stock,
            'is_active' => true,
        ]);
    }

    public function test_command_restores_stock_on_failed_order_payment(): void
    {
        $product = $this->makeProduct(5);

        $order = Order::query()->create([
            'order_number' => 'LFS-20260101-FAILED',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'subtotal' => 200,
            'total' => 200,
            'status' => 'pending_payment',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'size' => 'M',
            'qty' => 2,
            'unit_price' => 100,
            'line_total' => 200,
        ]);
        // Simulate the stock already having been reserved at order-creation time.
        $product->update(['total_stock' => 3, 'sizes' => [['size' => 'M', 'stock' => 3]]]);

        $payment = Payment::query()->create([
            'order_number' => $order->order_number,
            'payment_method' => 'mobile_money',
            'amount' => 200,
            'currency' => 'ZMW',
            'status' => 'pending',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'transaction_id' => 'LFS-FAILED-REF-1',
        ]);
        $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')
                ->once()
                ->with('LFS-FAILED-REF-1', true)
                ->andReturn([
                    'status' => 'declined',
                    'internalStatus' => 'failed',
                    'failureReason' => 'Insufficient funds',
                    'rawResponse' => [],
                ]);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('payment_failed', $order->fresh()->status);

        $product->refresh();
        $this->assertSame(5, $product->total_stock, 'stock should be restored when the poller resolves a failed payment');
        $this->assertSame(5, $product->sizes[0]['stock']);
    }

    public function test_command_cancels_and_restores_stock_for_expired_pending_payment(): void
    {
        $product = $this->makeProduct(5);

        $order = Order::query()->create([
            'order_number' => 'LFS-20260101-EXPIRED',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'subtotal' => 100,
            'total' => 100,
            'status' => 'pending_payment',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'size' => 'M',
            'qty' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);
        $product->update(['total_stock' => 4, 'sizes' => [['size' => 'M', 'stock' => 4]]]);

        $payment = Payment::query()->create([
            'order_number' => $order->order_number,
            'payment_method' => 'mobile_money',
            'amount' => 100,
            'currency' => 'ZMW',
            'status' => 'pending',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'transaction_id' => 'LFS-EXPIRED-REF-1',
        ]);
        // Old enough to be picked up by the poller, and its own expiry window
        // (plus grace period) has long since passed even though Lenco is still
        // reporting a non-terminal status — the customer never completed it.
        $payment->forceFill([
            'created_at' => now()->subMinutes(30),
            'expires_at' => now()->subMinutes(25),
        ])->save();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')
                ->once()
                ->with('LFS-EXPIRED-REF-1', true)
                ->andReturn([
                    'status' => 'pay-offline',
                    'internalStatus' => 'pending',
                    'rawResponse' => [],
                ]);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);

        $product->refresh();
        $this->assertSame(5, $product->total_stock, 'stock should be restored when a stale pending payment expires');
        $this->assertSame(5, $product->sizes[0]['stock']);
    }

    public function test_command_ignores_payments_not_yet_stale(): void
    {
        Payment::query()->create([
            'order_number' => 'LFS-20260101-FRESH',
            'payment_method' => 'mobile_money',
            'amount' => 100,
            'currency' => 'ZMW',
            'status' => 'pending',
            'customer_name' => 'Fresh Order',
            'customer_email' => 'fresh@example.com',
            'transaction_id' => 'LFS-ORDER-REF-2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldNotReceive('verifyPayment');
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();
    }
}
