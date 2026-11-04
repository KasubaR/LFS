<?php

namespace Tests\Feature\Tickets;

use App\Models\EventTicketOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesTicketFixtures;
use Tests\TestCase;

/**
 * The shared payment plumbing (webhook, verify endpoint, poller command) must
 * settle ticket payments through the ticket service and must leave shop
 * payments exactly as they were.
 */
class TicketPaymentHooksTest extends TestCase
{
    use MakesTicketFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMembershipBasics();
    }

    /**
     * @return array{0: EventTicketOrder, 1: Payment}
     */
    private function placePending(array $input = []): array
    {
        $this->mockLencoInitiation();
        $order = $this->placeTicketOrder($this->makePaidEvent(), $input)->order;

        return [$order, $this->paymentFor($order)];
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookBody(Payment $payment, string $status, float $amount = 650.0): array
    {
        return ['data' => [
            'id' => 'lenco-tx-'.$payment->id,
            'reference' => $payment->transaction_id,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'ZMW',
            'completedAt' => now()->toIso8601String(),
        ]];
    }

    // ── Webhook ──────────────────────────────────────────────────────────────

    public function test_webhook_success_confirms_the_ticket_order(): void
    {
        [$order, $payment] = $this->placePending();

        $this->postJson('/shop/checkout/webhook', $this->webhookBody($payment, 'successful'))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertTrue($payment->fresh()->webhook_received);
        $this->assertNotNull($payment->fresh()->webhook_payload);
    }

    public function test_duplicate_webhook_is_harmless(): void
    {
        [$order, $payment] = $this->placePending();
        $body = $this->webhookBody($payment, 'successful');

        $this->postJson('/shop/checkout/webhook', $body)->assertOk();
        $paidAt = $order->fresh()->paid_at;
        $this->postJson('/shop/checkout/webhook', $body)->assertOk();

        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
        $this->assertEquals($paidAt, $order->fresh()->paid_at);
    }

    public function test_webhook_failure_fails_the_ticket_order_and_releases_it(): void
    {
        [$order, $payment] = $this->placePending();

        $this->postJson('/shop/checkout/webhook', $this->webhookBody($payment, 'failed'))->assertOk();

        $this->assertSame(EventTicketOrder::STATUS_FAILED, $order->fresh()->status);
        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_webhook_with_the_wrong_amount_sends_the_order_to_review(): void
    {
        [$order, $payment] = $this->placePending();

        $this->postJson('/shop/checkout/webhook', $this->webhookBody($payment, 'successful', 100.0))->assertOk();

        $this->assertSame(EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED, $order->fresh()->status);
        $this->assertSame('amount_mismatch', $order->fresh()->review_reason);
    }

    public function test_ticket_webhook_never_touches_shop_orders(): void
    {
        $shop = Order::query()->create([
            'order_number' => 'LFS-20260101-ABCDE',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '0971234567',
            'subtotal' => 250,
            'total' => 250,
            'status' => 'pending_payment',
        ]);
        [$order, $payment] = $this->placePending();

        $this->postJson('/shop/checkout/webhook', $this->webhookBody($payment, 'successful'))->assertOk();

        $this->assertSame('pending_payment', $shop->fresh()->status);
        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
    }

    // ── Verify endpoint ──────────────────────────────────────────────────────

    public function test_verify_endpoint_settles_a_ticket_payment_for_the_session_that_placed_it(): void
    {
        [$order, $payment] = $this->placePending();
        $this->lencoPartial(function ($mock) use ($order): void {
            $mock->shouldReceive('verifyPayment')->once()->andReturn($this->lencoSuccess($order));
        });

        $this->withSession(["order_access.{$order->order_number}" => true])
            ->getJson('/shop/checkout/verify?txId='.urlencode($payment->transaction_id))
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'completed', 'orderStatus' => 'paid', 'orderNumber' => $order->order_number]);

        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
    }

    public function test_verify_endpoint_refuses_a_session_that_did_not_place_the_order(): void
    {
        [$order, $payment] = $this->placePending();

        $this->getJson('/shop/checkout/verify?txId='.urlencode($payment->transaction_id))->assertNotFound();

        $this->assertSame(EventTicketOrder::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_verify_endpoint_reports_gateway_outage_without_changing_anything(): void
    {
        [$order, $payment] = $this->placePending();
        $this->lencoPartial(function ($mock): void {
            $mock->shouldReceive('verifyPayment')->andThrow(new \RuntimeException('down'));
        });

        $this->withSession(["order_access.{$order->order_number}" => true])
            ->getJson('/shop/checkout/verify?txId='.urlencode($payment->transaction_id))
            ->assertStatus(503);

        $this->assertSame(EventTicketOrder::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    // ── Poller ───────────────────────────────────────────────────────────────

    public function test_poller_confirms_a_ticket_payment_whose_webhook_never_arrived(): void
    {
        [$order, $payment] = $this->placePending();
        $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $this->lencoPartial(function ($mock) use ($order, $payment): void {
            $mock->shouldReceive('verifyPayment')->once()->with($payment->transaction_id, true)
                ->andReturn($this->lencoSuccess($order));
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status);
        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_poller_releases_an_abandoned_reservation_but_keeps_the_payment_open(): void
    {
        [$order, $payment] = $this->placePending();
        $payment->forceFill(['created_at' => now()->subMinutes(40)])->save();
        $order->forceFill(['expires_at' => now()->subMinutes(30)])->save();
        $this->lencoPartial(function ($mock): void {
            $mock->shouldReceive('verifyPayment')->once()->andReturn(['status' => 'pending', 'internalStatus' => 'pending']);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame(EventTicketOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status, 'a late success must still be accepted');
    }

    public function test_poller_leaves_a_live_reservation_alone(): void
    {
        [$order, $payment] = $this->placePending();
        $payment->forceFill(['created_at' => now()->subMinutes(6)])->save();
        $this->lencoPartial(function ($mock): void {
            $mock->shouldReceive('verifyPayment')->once()->andReturn(['status' => 'pending', 'internalStatus' => 'pending']);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame(EventTicketOrder::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_poller_routes_a_late_success_through_the_late_payment_rule(): void
    {
        [$order, $payment] = $this->placePending();
        $payment->forceFill(['created_at' => now()->subMinutes(60)])->save();
        $order->forceFill(['status' => EventTicketOrder::STATUS_CANCELLED, 'expires_at' => now()->subMinutes(45)])->save();
        $this->lencoPartial(function ($mock) use ($order): void {
            $mock->shouldReceive('verifyPayment')->once()->andReturn($this->lencoSuccess($order));
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame(EventTicketOrder::STATUS_PAID, $order->fresh()->status, 'capacity was free, so it is revived');
    }

    public function test_poller_gives_up_on_a_payment_the_gateway_never_resolves(): void
    {
        [$order, $payment] = $this->placePending();
        $payment->forceFill(['created_at' => now()->subDays(3)])->save();
        $order->forceFill(['status' => EventTicketOrder::STATUS_CANCELLED, 'expires_at' => now()->subHours(30)])->save();
        $this->lencoPartial(function ($mock): void {
            $mock->shouldReceive('verifyPayment')->once()->andReturn(['status' => 'pending', 'internalStatus' => 'pending']);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame('cancelled', $payment->fresh()->status);
    }

    public function test_poller_does_not_run_shop_logic_on_ticket_payments(): void
    {
        [$order, $payment] = $this->placePending();
        $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();

        // One Lenco call total proves the ticket payment was not also handled by the shop branch.
        $this->lencoPartial(function ($mock) use ($order): void {
            $mock->shouldReceive('verifyPayment')->once()->andReturn($this->lencoSuccess($order));
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertDatabaseCount('orders', 0);
    }
}
