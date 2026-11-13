<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class OfflineSaleAdminTest extends TestCase
{
    use ActsAsAdmin, RefreshDatabase;

    private function makeProduct(int $stockM = 25, int $stockL = 5, float $price = 100): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Jersey',
            'slug' => 'test-jersey-'.Str::random(8),
            'price' => $price,
            'category' => 'jerseys',
            'gender' => 'unisex',
            'sizes' => [['size' => 'M', 'stock' => $stockM], ['size' => 'L', 'stock' => $stockL]],
            'total_stock' => $stockM + $stockL,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function salePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Walk In',
            'customer_phone' => '0971234567',
            'customer_email' => '',
            'payment_method' => 'cash',
            'notes' => '',
            'items' => [['product_id' => $product->id, 'size' => 'M', 'qty' => 2, 'unit_price' => '']],
        ], $overrides);
    }

    public function test_sale_form_lists_products_with_real_stock(): void
    {
        $this->makeProduct();

        $this->actingAsAdmin()->get('/admin/orders/sales/create')
            ->assertOk()
            ->assertSee('Record offline sale')
            ->assertSee('Test Jersey');
    }

    public function test_recording_a_sale_creates_an_offline_collected_order(): void
    {
        $product = $this->makeProduct();
        $admin = $this->makeAdminUser();

        $response = $this->actingAsAdmin($admin)->post('/admin/orders/sales', $this->salePayload($product, [
            'payment_method' => 'mobile_money',
            'customer_email' => 'Walk@Example.com',
        ]));

        $order = Order::query()->firstOrFail();
        $response->assertRedirect('/admin/orders/'.$order->id.'/receipt');

        $this->assertSame(OrderChannel::Offline, $order->channel);
        $this->assertSame(OrderStatus::Collected, $order->status);
        $this->assertSame('mobile_money', $order->payment_method);
        $this->assertSame($admin->id, $order->recorded_by);
        $this->assertNull($order->user_id);
        $this->assertSame('Walk In', $order->customer_name);
        $this->assertSame('0971234567', $order->customer_phone);
        $this->assertSame('walk@example.com', $order->customer_email);
        $this->assertStringStartsWith('LFS-S-', $order->order_number);
        $this->assertSame('200.00', (string) $order->total);

        $item = $order->items()->firstOrFail();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('M', $item->size);
        $this->assertSame(2, $item->qty);
        $this->assertSame('100.00', (string) $item->unit_price);
    }

    public function test_offline_sale_can_take_the_stock_the_website_keeps_in_reserve(): void
    {
        // 25 in stock, threshold 20: the website could only sell 5, but a
        // walk-in can take 10, leaving 15.
        $product = $this->makeProduct(stockM: 25);

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [['product_id' => $product->id, 'size' => 'M', 'qty' => 10, 'unit_price' => '']],
        ]))->assertRedirect();

        $product->refresh();
        $this->assertSame(15, $product->sizes[0]['stock']);
        $this->assertSame(20, $product->total_stock);
    }

    public function test_unit_price_can_be_overridden_and_multiple_lines_are_totalled(): void
    {
        $product = $this->makeProduct(stockM: 30, stockL: 30);

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [
                ['product_id' => $product->id, 'size' => 'M', 'qty' => 1, 'unit_price' => '80'],
                ['product_id' => $product->id, 'size' => 'L', 'qty' => 2, 'unit_price' => ''],
            ],
        ]))->assertRedirect();

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame('280.00', (string) $order->total); // 80 + 2 x 100
        $this->assertCount(2, $order->items);
    }

    public function test_sale_is_refused_when_it_exceeds_the_real_stock(): void
    {
        $product = $this->makeProduct(stockM: 25, stockL: 5);

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [['product_id' => $product->id, 'size' => 'L', 'qty' => 6, 'unit_price' => '']],
        ]))->assertSessionHasErrors('items');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(5, $product->fresh()->sizes[1]['stock']);
    }

    public function test_a_failed_line_rolls_back_the_whole_sale(): void
    {
        $product = $this->makeProduct(stockM: 25, stockL: 1);

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [
                ['product_id' => $product->id, 'size' => 'M', 'qty' => 3, 'unit_price' => ''],
                ['product_id' => $product->id, 'size' => 'L', 'qty' => 2, 'unit_price' => ''],
            ],
        ]))->assertSessionHasErrors('items');

        $product->refresh();
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(25, $product->sizes[0]['stock']); // first line's stock was given back
    }

    public function test_customer_name_phone_and_payment_method_are_required(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['customer_name' => '']))
            ->assertSessionHasErrors('customer_name');
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['customer_phone' => '']))
            ->assertSessionHasErrors('customer_phone');
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['customer_phone' => 'abc']))
            ->assertSessionHasErrors('customer_phone');
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['payment_method' => 'bitcoin']))
            ->assertSessionHasErrors('payment_method');
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_unknown_product_is_rejected(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [['product_id' => (string) Str::uuid(), 'size' => 'M', 'qty' => 1, 'unit_price' => '']],
        ]))->assertSessionHasErrors('items.0.product_id');
    }

    public function test_receipt_shows_the_sale(): void
    {
        $product = $this->makeProduct();
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product));
        $order = Order::query()->firstOrFail();

        $this->actingAsAdmin()->get('/admin/orders/'.$order->id.'/receipt')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Walk In')
            ->assertSee('Cash')
            ->assertSee('ZMW 200.00');
    }

    public function test_receipt_is_only_for_offline_sales(): void
    {
        $online = Order::query()->create([
            'order_number' => 'LFS-ONLINE-1', 'customer_name' => 'Jane', 'customer_email' => 'j@example.com',
            'subtotal' => 10, 'total' => 10, 'status' => OrderStatus::Paid,
        ]);

        $this->assertSame(OrderChannel::Online, $online->fresh()->channel);
        $this->actingAsAdmin()->get('/admin/orders/'.$online->id.'/receipt')->assertNotFound();
    }

    public function test_orders_list_can_be_filtered_by_channel(): void
    {
        $product = $this->makeProduct();
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product));
        $offline = Order::query()->firstOrFail();
        $online = Order::query()->create([
            'order_number' => 'LFS-ONLINE-2', 'customer_name' => 'Online Jane', 'customer_email' => 'j@example.com',
            'subtotal' => 10, 'total' => 10, 'status' => OrderStatus::Paid,
        ]);

        $this->actingAsAdmin()->get('/admin/orders?channel=offline')
            ->assertOk()->assertSee($offline->order_number)->assertDontSee($online->order_number);
        $this->actingAsAdmin()->get('/admin/orders?channel=online')
            ->assertOk()->assertSee($online->order_number)->assertDontSee($offline->order_number);
        $this->actingAsAdmin()->get('/admin/orders')
            ->assertOk()->assertSee($offline->order_number)->assertSee($online->order_number)
            ->assertSee('Record offline sale');
    }

    public function test_order_detail_shows_channel_and_payment_method(): void
    {
        $product = $this->makeProduct();
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['payment_method' => 'card']));
        $order = Order::query()->firstOrFail();

        $this->actingAsAdmin()->get('/admin/orders/'.$order->id)
            ->assertOk()
            ->assertSee('Offline (in person)')
            ->assertSee('Card / POS');
    }

    public function test_read_only_auditor_cannot_record_a_sale(): void
    {
        $product = $this->makeProduct();
        $auditor = $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);

        $this->actingAsAdmin($auditor)->post('/admin/orders/sales', $this->salePayload($product))
            ->assertForbidden();

        $this->assertSame(0, Order::query()->count());
        $this->assertInstanceOf(AdminUser::class, $auditor);
    }
}
