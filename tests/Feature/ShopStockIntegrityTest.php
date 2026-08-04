<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopStockIntegrityTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_place_order_decrements_stock(): void
    {
        $product = $this->makeProduct(5);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 'txn-1',
                'lencoReference' => 'lenco-ref-1',
                'reference' => 'LFS-STOCK-REF-1',
                'status' => 'pay-offline',
                'internalStatus' => 'pending',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'currency' => 'ZMW',
                'rawResponse' => [],
            ]);
        });

        $response = $this->withSession([
            'cart' => [['price' => 100, 'qty' => 2, 'productId' => $product->id, 'size' => 'M']],
        ])->postJson('/shop/checkout/place-order', [
            'customerInfo' => ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
            'paymentMethod' => 'mobile_money',
            'provider' => 'mtn',
            'customerPhone' => '+260971234567',
        ]);

        $response->assertOk();

        $product->refresh();
        $this->assertSame(3, $product->total_stock);
        $this->assertSame(3, $product->sizes[0]['stock']);
    }

    public function test_place_order_rejects_when_cart_quantity_exceeds_stock(): void
    {
        $product = $this->makeProduct(1);

        $response = $this->withSession([
            'cart' => [['price' => 100, 'qty' => 5, 'productId' => $product->id, 'size' => 'M']],
        ])->postJson('/shop/checkout/place-order', [
            'customerInfo' => ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
            'paymentMethod' => 'mobile_money',
            'provider' => 'mtn',
            'customerPhone' => '+260971234567',
        ]);

        $response->assertStatus(409);
        $this->assertSame(0, Order::query()->count());

        $product->refresh();
        $this->assertSame(1, $product->total_stock);
        $this->assertSame(1, $product->sizes[0]['stock']);
    }

    public function test_failed_payment_restores_stock(): void
    {
        $product = $this->makeProduct(5);

        $this->mock(LencoService::class, function ($mock) {
            $mock->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
                'transactionId' => 'txn-1',
                'lencoReference' => 'lenco-ref-1',
                'reference' => 'LFS-RESTORE-REF-1',
                'status' => 'pay-offline',
                'internalStatus' => 'pending',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'currency' => 'ZMW',
                'rawResponse' => [],
            ]);
            $mock->shouldReceive('verifyPayment')->once()->with('LFS-RESTORE-REF-1', true)->andReturn([
                'status' => 'failed',
                'internalStatus' => 'failed',
                'rawResponse' => [],
            ]);
        });

        $this->withSession([
            'cart' => [['price' => 100, 'qty' => 2, 'productId' => $product->id, 'size' => 'M']],
        ])->postJson('/shop/checkout/place-order', [
            'customerInfo' => ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
            'paymentMethod' => 'mobile_money',
            'provider' => 'mtn',
            'customerPhone' => '+260971234567',
        ])->assertOk();

        $product->refresh();
        $this->assertSame(3, $product->total_stock, 'stock should be reserved after placing the order');

        $verifyResponse = $this->getJson('/shop/checkout/verify?txId=LFS-RESTORE-REF-1');
        $verifyResponse->assertOk();
        $verifyResponse->assertJson(['status' => 'failed']);

        $order = Order::query()->where('order_number', '!=', '')->latest('id')->first();
        $this->assertSame('payment_failed', $order->status);

        $payment = Payment::query()->where('transaction_id', 'LFS-RESTORE-REF-1')->first();
        $this->assertSame('failed', $payment->status);

        $product->refresh();
        $this->assertSame(5, $product->total_stock, 'stock should be restored after payment failure');
        $this->assertSame(5, $product->sizes[0]['stock']);
    }
}
