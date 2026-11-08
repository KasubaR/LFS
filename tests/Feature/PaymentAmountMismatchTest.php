<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\LencoService;
use App\Support\PaymentAmount;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A confirmed charge must be for the amount we asked for. A short-paid,
 * over-paid or wrong-currency charge is held for review, never marked paid.
 */
class PaymentAmountMismatchTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-api-secret-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lenco.api_secret_key' => self::KEY]);
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    public function test_helper_accepts_exact_amount_and_ignores_float_noise(): void
    {
        $this->assertNull(PaymentAmount::mismatch(250.0, ['amount' => '250.00', 'currency' => 'ZMW']));
        $this->assertNull(PaymentAmount::mismatch(19.99, ['amount' => 19.990000001, 'currency' => 'zmw']));
    }

    public function test_helper_flags_short_over_and_foreign_currency(): void
    {
        $this->assertSame(100.0, PaymentAmount::mismatch(250.0, ['amount' => '100', 'currency' => 'ZMW'])['received']);
        $this->assertNotNull(PaymentAmount::mismatch(250.0, ['amount' => '300', 'currency' => 'ZMW']));
        $this->assertSame('USD', PaymentAmount::mismatch(250.0, ['amount' => '250', 'currency' => 'USD'])['currency']);
    }

    public function test_helper_cannot_judge_a_result_with_no_amount(): void
    {
        $this->assertNull(PaymentAmount::mismatch(250.0, ['status' => 'successful']));
    }

    // ── Shop ─────────────────────────────────────────────────────────────────

    private function makeShopPayment(string $orderNumber = 'LFS-20260101-AMT01'): Payment
    {
        Order::query()->create([
            'order_number' => $orderNumber,
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '0971234567',
            'subtotal' => 250,
            'total' => 250,
            'status' => 'pending_payment',
        ]);

        return Payment::query()->create([
            'order_number' => $orderNumber,
            'payment_method' => 'mobile_money',
            'amount' => 250,
            'currency' => 'ZMW',
            'status' => 'pending',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'transaction_id' => 'REF-'.$orderNumber,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function signedWebhook(string $url, array $data): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode(['data' => $data]);

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LENCO_SIGNATURE' => hash_hmac('sha512', $raw, hash('sha256', self::KEY)),
        ], $raw);
    }

    public function test_shop_webhook_with_the_right_amount_marks_the_order_paid(): void
    {
        $payment = $this->makeShopPayment();

        $this->signedWebhook('/shop/checkout/webhook', [
            'reference' => $payment->transaction_id, 'status' => 'successful', 'amount' => '250.00', 'currency' => 'ZMW',
        ])->assertOk();

        $this->assertSame('paid', Order::query()->where('order_number', $payment->order_number)->value('status'));
        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_shop_webhook_with_a_short_payment_holds_the_order_for_review(): void
    {
        $payment = $this->makeShopPayment();

        $this->signedWebhook('/shop/checkout/webhook', [
            'reference' => $payment->transaction_id, 'status' => 'successful', 'amount' => '100.00', 'currency' => 'ZMW',
        ])->assertOk();

        $this->assertSame('payment_review', Order::query()->where('order_number', $payment->order_number)->value('status'));
        $fresh = $payment->fresh();
        $this->assertSame('completed', $fresh->status, 'money did arrive, so the payment row is settled');
        $this->assertStringContainsString('amount_mismatch', (string) $fresh->failure_reason);
    }

    public function test_shop_webhook_in_the_wrong_currency_is_held_for_review(): void
    {
        $payment = $this->makeShopPayment();

        $this->signedWebhook('/shop/checkout/webhook', [
            'reference' => $payment->transaction_id, 'status' => 'successful', 'amount' => '250.00', 'currency' => 'USD',
        ])->assertOk();

        $this->assertSame('payment_review', Order::query()->where('order_number', $payment->order_number)->value('status'));
    }

    public function test_shop_verify_endpoint_holds_a_wrong_amount_for_review(): void
    {
        $payment = $this->makeShopPayment();

        $this->mock(LencoService::class, function ($mock) use ($payment) {
            $mock->shouldReceive('verifyPayment')->once()->andReturn([
                'status' => 'successful', 'internalStatus' => 'completed', 'amount' => '10.00', 'currency' => 'ZMW',
            ]);
        });

        $this->withSession(['order_access.'.$payment->order_number => true])
            ->getJson('/shop/checkout/verify?txId='.$payment->transaction_id)
            ->assertOk();

        $this->assertSame('payment_review', Order::query()->where('order_number', $payment->order_number)->value('status'));
    }

    public function test_poller_holds_a_wrong_amount_for_review(): void
    {
        $payment = $this->makeShopPayment();
        $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')->andReturn([
                'status' => 'successful', 'internalStatus' => 'completed', 'amount' => '1.00', 'currency' => 'ZMW',
            ]);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $this->assertSame('payment_review', Order::query()->where('order_number', $payment->order_number)->value('status'));
    }

    // ── Membership ───────────────────────────────────────────────────────────

    private function makePendingMembershipCharge(float $charge = 250.0): MembershipPayment
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->first();
        $membership = Membership::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'membership_number' => 'LFS-000777',
            'status' => MembershipStatus::PendingPayment,
            'current_plan_id' => $plan->id,
            'approval_status' => 'pending',
        ]);

        return MembershipPayment::query()->create([
            'membership_id' => $membership->id,
            'plan_id' => $plan->id,
            'amount' => 1000,
            'amount_paid' => 0,
            'pending_charge_amount' => $charge,
            'currency' => 'ZMW',
            'payment_reference' => 'MREF-777',
            'payment_gateway' => 'lenco',
            'status' => 'pending',
        ]);
    }

    public function test_membership_webhook_with_the_right_amount_credits_the_charge(): void
    {
        $payment = $this->makePendingMembershipCharge(250.0);

        $this->signedWebhook('/account/payment/webhook', [
            'reference' => 'MREF-777', 'status' => 'successful', 'amount' => '250.00', 'currency' => 'ZMW',
        ])->assertOk();

        $this->assertSame('250.00', $payment->fresh()->amount_paid);
    }

    public function test_membership_webhook_with_a_wrong_amount_is_not_credited_and_is_flagged(): void
    {
        $payment = $this->makePendingMembershipCharge(250.0);

        $this->signedWebhook('/account/payment/webhook', [
            'reference' => 'MREF-777', 'status' => 'successful', 'amount' => '50.00', 'currency' => 'ZMW',
        ])->assertOk()->assertJson(['ok' => true]);

        $fresh = $payment->fresh();
        $this->assertSame('0.00', $fresh->amount_paid);
        $this->assertSame('pending', $fresh->status);
        $this->assertEquals(50.0, $fresh->metadata['amountMismatch']['received']);
        $this->assertEquals(250.0, $fresh->metadata['amountMismatch']['expected']);
        $this->assertSame(MembershipStatus::PendingPayment, Membership::query()->find($fresh->membership_id)->status);
        $this->assertTrue((bool) $fresh->webhook_received, 'the webhook itself is still recorded');
    }

    public function test_flagged_membership_payment_stays_held_on_a_later_correct_looking_confirmation(): void
    {
        $payment = $this->makePendingMembershipCharge(250.0);

        $this->signedWebhook('/account/payment/webhook', [
            'reference' => 'MREF-777', 'status' => 'successful', 'amount' => '50.00', 'currency' => 'ZMW',
        ])->assertOk();
        $this->signedWebhook('/account/payment/webhook', [
            'reference' => 'MREF-777', 'status' => 'successful', 'amount' => '250.00', 'currency' => 'ZMW',
        ])->assertOk();

        $this->assertSame('0.00', $payment->fresh()->amount_paid, 'a flagged payment is only released by an admin');
    }

    public function test_membership_verify_endpoint_does_not_credit_a_wrong_amount(): void
    {
        $payment = $this->makePendingMembershipCharge(250.0);
        $user = Membership::query()->find($payment->membership_id)->user;

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')->once()->andReturn([
                'status' => 'successful', 'internalStatus' => 'completed', 'amount' => '5.00', 'currency' => 'ZMW', 'rawResponse' => [],
            ]);
        });

        $this->actingAs($user)
            ->getJson('/account/payment/verify?reference=MREF-777')
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'pending']);

        $this->assertSame('0.00', $payment->fresh()->amount_paid);
    }

    public function test_poller_does_not_credit_a_wrong_membership_amount(): void
    {
        $payment = $this->makePendingMembershipCharge(250.0);
        $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')->andReturn([
                'status' => 'successful', 'internalStatus' => 'completed', 'amount' => '5.00', 'currency' => 'ZMW', 'rawResponse' => [],
            ]);
        });

        $this->artisan('payments:poll-pending')->assertSuccessful();

        $fresh = $payment->fresh();
        $this->assertSame('0.00', $fresh->amount_paid);
        $this->assertNotNull($fresh->metadata['amountMismatch'] ?? null);
    }
}
