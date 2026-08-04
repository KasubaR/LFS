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
