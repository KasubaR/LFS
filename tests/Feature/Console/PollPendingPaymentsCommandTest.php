<?php

namespace Tests\Feature\Console;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
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
