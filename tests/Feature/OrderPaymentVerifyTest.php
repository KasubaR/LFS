<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPaymentVerifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_rejects_txid_not_granted_to_this_session(): void
    {
        Order::query()->create([
            'order_number' => 'LFS-20260101-NOACCESS',
            'customer_name' => 'Someone Else',
            'customer_email' => 'someone@example.com',
            'subtotal' => 100,
            'total' => 100,
            'status' => 'pending_payment',
        ]);

        Payment::query()->create([
            'order_number' => 'LFS-20260101-NOACCESS',
            'payment_method' => 'mobile_money',
            'amount' => 100,
            'currency' => 'ZMW',
            'status' => 'pending',
            'customer_name' => 'Someone Else',
            'customer_email' => 'someone@example.com',
            'transaction_id' => 'LFS-NOACCESS-REF',
        ]);

        // No /shop/checkout/place-order call was made in this session, so no
        // order_access grant exists for this txId — probing it must be rejected.
        $response = $this->getJson('/shop/checkout/verify?txId=LFS-NOACCESS-REF');

        $response->assertStatus(403);
    }

    public function test_verify_returns_404_for_unknown_txid(): void
    {
        $response = $this->getJson('/shop/checkout/verify?txId=does-not-exist');

        $response->assertStatus(404);
    }

    public function test_verify_succeeds_for_the_session_that_placed_the_order(): void
    {
        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 'txn-1',
                'lencoReference' => 'lenco-ref-1',
                'reference' => 'LFS-OWN-REF-1',
                'status' => 'pay-offline',
                'internalStatus' => 'pending',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'currency' => 'ZMW',
                'rawResponse' => [],
            ]);
            $mock->shouldReceive('verifyPayment')->once()->with('LFS-OWN-REF-1', true)->andReturn([
                'status' => 'pending',
                'internalStatus' => 'pending',
                'rawResponse' => [],
            ]);
        });

        $placeResponse = $this->withSession([
            'cart' => [['price' => 100, 'qty' => 1]],
        ])->postJson('/shop/checkout/place-order', [
            'customerInfo' => ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
            'paymentMethod' => 'mobile_money',
            'provider' => 'mtn',
            'customerPhone' => '+260971234567',
        ]);

        $placeResponse->assertOk();

        $verifyResponse = $this->getJson('/shop/checkout/verify?txId=LFS-OWN-REF-1');

        $verifyResponse->assertOk();
        $verifyResponse->assertJson(['ok' => true]);
    }
}
