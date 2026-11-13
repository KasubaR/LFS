<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\ShopSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class ShopSoldOutThresholdTest extends TestCase
{
    use ActsAsAdmin, RefreshDatabase;

    private function makeProduct(int $stockM, int $stockL = 100): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Jersey',
            'slug' => 'test-jersey-'.Str::random(8),
            'price' => 100,
            'category' => 'jerseys',
            'gender' => 'unisex',
            'sizes' => [['size' => 'M', 'stock' => $stockM], ['size' => 'L', 'stock' => $stockL]],
            'total_stock' => $stockM + $stockL,
            'is_active' => true,
        ]);
    }

    private function placeOrder(Product $product, string $size, int $qty)
    {
        return $this->withSession([
            'cart' => [['price' => 100, 'qty' => $qty, 'productId' => $product->id, 'size' => $size]],
        ])->postJson('/shop/checkout/place-order', [
            'customerInfo' => ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
            'paymentMethod' => 'mobile_money',
            'provider' => 'mtn',
            'customerPhone' => '+260971234567',
        ]);
    }

    public function test_threshold_defaults_to_twenty(): void
    {
        $this->assertSame(20, app(ShopSettingsService::class)->onlineSelloutThreshold());
    }

    public function test_online_stock_is_stock_above_the_threshold(): void
    {
        $settings = app(ShopSettingsService::class);

        $this->assertSame(5, $settings->onlineStock(25));
        $this->assertSame(0, $settings->onlineStock(20));
        $this->assertSame(0, $settings->onlineStock(19));
        $this->assertSame(0, $settings->onlineStock(0));

        $settings->setOnlineSelloutThreshold(0);
        $this->assertSame(7, $settings->onlineStock(7));
    }

    public function test_public_product_data_hides_the_reserved_stock_per_size(): void
    {
        $product = $this->makeProduct(stockM: 19, stockL: 45);

        $public = app(ProductService::class)->findById($product->id);

        $this->assertSame(0, $public['sizes'][0]['stock']);   // M is at/below the threshold
        $this->assertSame(25, $public['sizes'][1]['stock']);  // L has 45, 20 reserved
        $this->assertSame(25, $public['totalStock']);
    }

    public function test_admin_product_data_keeps_the_real_stock(): void
    {
        $product = $this->makeProduct(stockM: 19, stockL: 45);

        $admin = app(ProductService::class)->getProductById($product->id, ['admin' => true]);

        $this->assertSame(19, $admin['sizes'][0]['stock']);
        $this->assertSame(0, $admin['sizes'][0]['onlineStock']);
        $this->assertSame(45, $admin['sizes'][1]['stock']);
        $this->assertSame(25, $admin['sizes'][1]['onlineStock']);
        $this->assertSame(64, $admin['totalStock']);
    }

    public function test_shop_page_shows_a_size_at_the_threshold_as_sold_out(): void
    {
        $this->makeProduct(stockM: 10, stockL: 15);

        $this->get('/shop')->assertOk()->assertSee('Sold Out');
    }

    public function test_shop_page_does_not_show_sold_out_when_stock_is_above_the_threshold(): void
    {
        $this->makeProduct(stockM: 50, stockL: 50);

        $this->get('/shop')->assertOk()->assertDontSee('Sold Out');
    }

    public function test_checkout_refuses_a_size_at_or_below_the_threshold(): void
    {
        $product = $this->makeProduct(stockM: 20);

        $this->placeOrder($product, 'M', 1)->assertStatus(409);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(20, $product->fresh()->sizes[0]['stock']);
    }

    public function test_checkout_cannot_take_the_reserved_units(): void
    {
        $product = $this->makeProduct(stockM: 25);

        // 25 in stock, 20 reserved: 5 can be sold online, not 6.
        $this->placeOrder($product, 'M', 6)->assertStatus(409);
        $this->assertSame(25, $product->fresh()->sizes[0]['stock']);
    }

    public function test_decrement_for_offline_use_can_take_the_reserved_units(): void
    {
        $product = $this->makeProduct(stockM: 25);
        $service = app(ProductService::class);

        try {
            $service->decrementStockForOrder($product->id, 'M', 10);
            $this->fail('Online decrement should refuse to go below the threshold.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Only 5 left', $e->getMessage());
        }

        $service->decrementStockForOrder($product->id, 'M', 10, respectThreshold: false);

        $this->assertSame(15, $product->fresh()->sizes[0]['stock']);
    }

    public function test_admin_can_change_the_threshold(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/products/settings', ['online_sellout_threshold' => 8])
            ->assertRedirect('/admin/products');

        $this->assertSame(8, app(ShopSettingsService::class)->onlineSelloutThreshold());
        $this->assertDatabaseHas('shop_settings', ['id' => 1, 'online_sellout_threshold' => 8]);

        // The new number takes effect straight away.
        $product = $this->makeProduct(stockM: 10);
        $public = app(ProductService::class)->findById($product->id);
        $this->assertSame(2, $public['sizes'][0]['stock']);
    }

    public function test_threshold_must_be_a_non_negative_whole_number(): void
    {
        $this->actingAsAdmin()->post('/admin/products/settings', ['online_sellout_threshold' => -1])
            ->assertSessionHasErrors('online_sellout_threshold');
        $this->actingAsAdmin()->post('/admin/products/settings', ['online_sellout_threshold' => 'abc'])
            ->assertSessionHasErrors('online_sellout_threshold');
        $this->actingAsAdmin()->post('/admin/products/settings', [])
            ->assertSessionHasErrors('online_sellout_threshold');

        $this->assertSame(20, app(ShopSettingsService::class)->onlineSelloutThreshold());
    }

    public function test_admin_product_list_shows_the_setting_and_real_stock(): void
    {
        $this->makeProduct(stockM: 19, stockL: 45);

        $this->actingAsAdmin()->get('/admin/products')
            ->assertOk()
            ->assertSee('Online sell-out threshold')
            ->assertSee('64');
    }
}
