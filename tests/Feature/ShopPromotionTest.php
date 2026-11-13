<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Exceptions\PromoCodeException;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShopPromotion;
use App\Models\User;
use App\Services\LencoService;
use App\Services\ShopPromotionService;
use App\Support\Uuid;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(\App\Services\ShopSettingsService::class)->setOnlineSelloutThreshold(0);
    }

    private function makeProduct(string $category = 'jerseys', float $price = 100, int $stock = 20): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test '.$category,
            'slug' => 'test-'.$category.'-'.Str::random(6),
            'price' => $price,
            'category' => $category,
            'gender' => 'unisex',
            'sizes' => [['size' => 'M', 'stock' => $stock]],
            'total_stock' => $stock,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makePromo(array $overrides = []): ShopPromotion
    {
        return ShopPromotion::query()->create(array_merge([
            'name' => 'Promo',
            'code' => 'SAVE10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'category' => null,
            'members_only' => false,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ], $overrides));
    }

    /** @return list<array{productId: string, qty: int}> */
    private function lines(Product ...$products): array
    {
        return array_map(fn (Product $p): array => ['productId' => $p->id, 'qty' => 1], $products);
    }

    private function makeActiveMember(): User
    {
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
        $user = User::factory()->create();
        Membership::query()->create([
            'id' => Uuid::v4(),
            'user_id' => $user->id,
            'membership_number' => '9'.random_int(1000, 9999),
            'public_token' => Uuid::v4(),
            'status' => MembershipStatus::Active,
            'start_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addMonths(11)->toDateString(),
            'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved',
            'joined_at' => now()->subMonth(),
        ]);

        return $user;
    }

    private function service(): ShopPromotionService
    {
        return app(ShopPromotionService::class);
    }

    // ── Pricing rules ────────────────────────────────────────────────────────

    public function test_percentage_code_discounts_the_cart(): void
    {
        $this->makePromo();
        $result = $this->service()->priceCart($this->lines($this->makeProduct()), 'save10');

        $this->assertSame(100.0, $result['subtotal']);
        $this->assertSame(10.0, $result['discount']);
        $this->assertSame(90.0, $result['total']);
        $this->assertSame('SAVE10', $result['code']);
    }

    public function test_fixed_code_is_shared_across_eligible_lines(): void
    {
        $this->makePromo(['code' => 'K30', 'discount_type' => 'fixed', 'discount_value' => 30]);
        $result = $this->service()->priceCart(
            $this->lines($this->makeProduct('jerseys', 100), $this->makeProduct('hoodies', 200)),
            'K30',
        );

        $this->assertSame(30.0, $result['discount']);
        $this->assertSame(270.0, $result['total']);
    }

    public function test_code_only_discounts_its_category(): void
    {
        $this->makePromo(['category' => 'hoodies', 'discount_value' => 50]);
        $result = $this->service()->priceCart(
            $this->lines($this->makeProduct('jerseys', 100), $this->makeProduct('hoodies', 200)),
            'SAVE10',
        );

        $this->assertSame(100.0, $result['discount']);
        $this->assertSame(0.0, $result['lines'][0]['discount']);
        $this->assertSame(100.0, $result['lines'][1]['discount']);
    }

    public function test_code_for_another_category_is_rejected(): void
    {
        $this->makePromo(['category' => 'hoodies']);

        $this->expectException(PromoCodeException::class);
        $this->service()->priceCart($this->lines($this->makeProduct('jerseys')), 'SAVE10');
    }

    public function test_automatic_sale_applies_without_a_code_and_the_largest_wins(): void
    {
        $this->makePromo(['code' => null, 'name' => 'Small', 'category' => 'jerseys', 'discount_value' => 10]);
        $this->makePromo(['code' => null, 'name' => 'Big', 'category' => 'jerseys', 'discount_value' => 25]);
        $this->makePromo(['code' => null, 'name' => 'Other', 'category' => 'hoodies', 'discount_value' => 50]);

        $result = $this->service()->priceCart($this->lines($this->makeProduct('jerseys', 100)));

        $this->assertSame(25.0, $result['discount']);
        $this->assertSame(75.0, $result['total']);
        $this->assertSame(['Big'], $result['saleNames']);
        $this->assertNull($result['code']);
    }

    public function test_sale_and_code_never_stack_the_better_one_wins(): void
    {
        $this->makePromo(['code' => null, 'name' => 'Sale', 'discount_value' => 30]);
        $this->makePromo(['code' => 'BIG', 'discount_value' => 40]);
        $product = $this->makeProduct();

        $better = $this->service()->priceCart($this->lines($product), 'BIG');
        $this->assertSame(40.0, $better['discount']);

        ShopPromotion::query()->where('code', 'BIG')->update(['discount_value' => 20]);
        $this->expectException(PromoCodeException::class);
        $this->service()->priceCart($this->lines($product), 'BIG');
    }

    #[DataProvider('unusableCodes')]
    public function test_unusable_codes_are_rejected(array $overrides, string $code): void
    {
        $this->makePromo($overrides);

        $this->expectException(PromoCodeException::class);
        $this->service()->priceCart($this->lines($this->makeProduct()), $code);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function unusableCodes(): array
    {
        return [
            'unknown' => [[], 'NOPE'],
            'disabled' => [['is_active' => false], 'SAVE10'],
            'expired' => [['ends_at' => date('Y-m-d', strtotime('-1 day')), 'starts_at' => date('Y-m-d', strtotime('-1 week'))], 'SAVE10'],
            'not started' => [['starts_at' => date('Y-m-d', strtotime('+1 day')), 'ends_at' => date('Y-m-d', strtotime('+1 week'))], 'SAVE10'],
            'below minimum' => [['min_order_amount' => 500], 'SAVE10'],
        ];
    }

    public function test_dates_are_inclusive_on_both_ends(): void
    {
        $this->makePromo(['starts_at' => now()->toDateString(), 'ends_at' => now()->toDateString()]);

        $result = $this->service()->priceCart($this->lines($this->makeProduct()), 'SAVE10');

        $this->assertSame(10.0, $result['discount']);
    }

    public function test_a_code_can_never_make_the_order_free(): void
    {
        $this->makePromo(['code' => 'FREE', 'discount_type' => 'fixed', 'discount_value' => 500]);

        $this->expectException(PromoCodeException::class);
        $this->service()->priceCart($this->lines($this->makeProduct('jerseys', 100)), 'FREE');
    }

    public function test_hand_set_prices_are_never_discounted(): void
    {
        $this->makePromo();
        $this->makePromo(['code' => null, 'name' => 'Sale', 'discount_value' => 50]);
        $product = $this->makeProduct();

        $result = $this->service()->priceCart([
            ['productId' => $product->id, 'qty' => 1, 'unitPrice' => 80.0, 'fixedPrice' => true],
        ]);

        $this->assertSame(0.0, $result['discount']);
        $this->assertSame(80.0, $result['total']);
    }

    public function test_members_only_sale_and_code_require_an_active_member(): void
    {
        $this->makePromo(['code' => null, 'name' => 'Members sale', 'discount_value' => 20, 'members_only' => true]);
        $this->makePromo(['code' => 'MEMBER', 'discount_value' => 30, 'members_only' => true]);
        $product = $this->makeProduct();
        $member = $this->makeActiveMember();

        $guest = $this->service()->priceCart($this->lines($product));
        $this->assertSame(0.0, $guest['discount']);
        $this->assertNull($this->service()->saleFor('jerseys', 100));

        $this->assertSame(20.0, $this->service()->priceCart($this->lines($product), null, $member)['discount']);
        $this->assertSame(30.0, $this->service()->priceCart($this->lines($product), 'MEMBER', $member)['discount']);

        try {
            $this->service()->priceCart($this->lines($product), 'MEMBER', null);
            $this->fail('Guest used a members-only code.');
        } catch (PromoCodeException) {
            $this->addToAssertionCount(1);
        }

        Membership::query()->where('user_id', $member->id)->update(['expiry_date' => now()->subDay()->toDateString()]);
        $this->expectException(PromoCodeException::class);
        $this->service()->priceCart($this->lines($product), 'MEMBER', $member->fresh());
    }

    // ── Storefront ───────────────────────────────────────────────────────────

    public function test_product_page_shows_the_automatic_sale_price(): void
    {
        $this->makePromo(['code' => null, 'name' => 'Jersey sale', 'category' => 'jerseys', 'discount_value' => 20]);
        $product = $this->makeProduct('jerseys', 200);

        $this->get('/shop/product/'.$product->slug)
            ->assertOk()
            ->assertSee('K 160.00')
            ->assertSee('K 200.00')
            ->assertSee('Save 20%');
    }

    public function test_cart_page_applies_the_sale_and_checkout_shows_the_discount(): void
    {
        $this->makePromo(['code' => null, 'name' => 'Jersey sale', 'category' => 'jerseys', 'discount_value' => 20]);
        $product = $this->makeProduct('jerseys', 200);
        $cart = [['key' => $product->id.'::M', 'productId' => $product->id, 'slug' => $product->slug, 'name' => 'Test', 'price' => 200.0, 'image' => '', 'size' => 'M', 'qty' => 1]];

        $this->withSession(['cart' => $cart])->get('/shop/cart')
            ->assertOk()
            ->assertSee('Sale discount')
            ->assertSee('K 160');

        $this->withSession(['cart' => $cart])->get('/shop/checkout')
            ->assertOk()
            ->assertSee('Sale discount');
    }

    public function test_apply_and_remove_promo_code(): void
    {
        $this->makePromo();
        $product = $this->makeProduct('jerseys', 100);
        $cart = [['key' => $product->id.'::M', 'productId' => $product->id, 'slug' => $product->slug, 'name' => 'Test', 'price' => 100.0, 'image' => '', 'size' => 'M', 'qty' => 2]];

        $this->withSession(['cart' => $cart])
            ->postJson('/shop/cart/promo', ['code' => 'nope'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->withSession(['cart' => $cart])
            ->postJson('/shop/cart/promo', ['code' => ' save10 '])
            ->assertOk()
            ->assertJson(['ok' => true, 'hasDiscount' => true, 'code' => 'SAVE10', 'total' => 'K 180.00'])
            ->assertSessionHas('cart_promo_code', 'SAVE10');

        $this->withSession(['cart' => $cart, 'cart_promo_code' => 'SAVE10'])
            ->postJson('/shop/cart/promo/remove')
            ->assertOk()
            ->assertJson(['hasDiscount' => false, 'total' => 'K 200.00'])
            ->assertSessionMissing('cart_promo_code');
    }

    public function test_a_code_that_stops_applying_is_dropped_from_the_cart(): void
    {
        $this->makePromo(['category' => 'hoodies']);
        $product = $this->makeProduct('jerseys', 100);
        $cart = [['key' => $product->id.'::M', 'productId' => $product->id, 'slug' => $product->slug, 'name' => 'Test', 'price' => 100.0, 'image' => '', 'size' => 'M', 'qty' => 1]];

        $this->withSession(['cart' => $cart, 'cart_promo_code' => 'SAVE10'])
            ->get('/shop/cart')
            ->assertOk()
            ->assertSessionMissing('cart_promo_code');
    }

    // ── Placing an order ─────────────────────────────────────────────────────

    private function mockLenco(): void
    {
        $this->mock(LencoService::class, function ($mock): void {
            $mock->shouldReceive('generateReference')->andReturn('LFS-PROMO-REF-1');
            $mock->shouldReceive('initiateMobileMoneyPayment')->andReturn([
                'transactionId' => 'txn-1',
                'lencoReference' => 'lenco-ref-1',
                'reference' => 'LFS-PROMO-REF-1',
                'status' => 'pay-offline',
                'internalStatus' => 'pending',
                'paymentInstructions' => 'Approve on your phone',
                'paymentUrl' => null,
                'expiresAt' => null,
                'currency' => 'ZMW',
                'rawResponse' => [],
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function orderBody(): array
    {
        return [
            'customerInfo' => ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
            'paymentMethod' => 'mobile_money',
            'provider' => 'mtn',
            'customerPhone' => '+260971234567',
        ];
    }

    public function test_order_with_a_code_is_charged_the_discounted_amount(): void
    {
        $this->makePromo();
        $product = $this->makeProduct('jerseys', 100);
        $this->mockLenco();

        $this->withSession([
            'cart' => [['price' => 100, 'qty' => 2, 'productId' => $product->id, 'size' => 'M']],
            'cart_promo_code' => 'SAVE10',
        ])->postJson('/shop/checkout/place-order', $this->orderBody())->assertOk();

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame(200.0, (float) $order->subtotal);
        $this->assertSame(20.0, (float) $order->discount_amount);
        $this->assertSame(180.0, (float) $order->total);
        $this->assertSame('SAVE10', $order->promo_code);
        $this->assertSame(ShopPromotion::query()->value('id'), $order->shop_promotion_id);
        $this->assertSame(20.0, (float) $order->items->first()->discount_amount);

        // What Lenco is asked to charge must equal the order total, or paid orders get held for review.
        $this->assertSame(180.0, (float) Payment::query()->firstOrFail()->amount);
    }

    public function test_order_with_an_automatic_sale_needs_no_code(): void
    {
        $this->makePromo(['code' => null, 'name' => 'Sale', 'discount_value' => 50]);
        $product = $this->makeProduct('jerseys', 100);
        $this->mockLenco();

        // Even a stale cart price can't beat the server: it re-prices from the database.
        $this->withSession([
            'cart' => [['price' => 1, 'qty' => 1, 'productId' => $product->id, 'size' => 'M']],
        ])->postJson('/shop/checkout/place-order', $this->orderBody())->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertSame(100.0, (float) $order->subtotal);
        $this->assertSame(50.0, (float) $order->total);
        $this->assertNull($order->promo_code);
        $this->assertSame(50.0, (float) Payment::query()->firstOrFail()->amount);
    }

    public function test_order_without_a_promotion_is_unchanged(): void
    {
        $product = $this->makeProduct('jerseys', 100);
        $this->mockLenco();

        $this->withSession([
            'cart' => [['price' => 100, 'qty' => 1, 'productId' => $product->id, 'size' => 'M']],
        ])->postJson('/shop/checkout/place-order', $this->orderBody())->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertSame(100.0, (float) $order->total);
        $this->assertSame(0.0, (float) $order->discount_amount);
    }

    public function test_an_invalid_code_at_checkout_creates_no_order_and_keeps_the_stock(): void
    {
        $this->makePromo(['ends_at' => now()->subDay()->toDateString(), 'starts_at' => now()->subWeek()->toDateString()]);
        $product = $this->makeProduct('jerseys', 100, 5);

        $this->withSession([
            'cart' => [['price' => 100, 'qty' => 2, 'productId' => $product->id, 'size' => 'M']],
            'cart_promo_code' => 'SAVE10',
        ])->postJson('/shop/checkout/place-order', $this->orderBody())
            ->assertStatus(422)
            ->assertSessionMissing('cart_promo_code');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(5, $product->fresh()->total_stock);
    }
}
