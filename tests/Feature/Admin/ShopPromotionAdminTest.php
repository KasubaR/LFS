<?php

namespace Tests\Feature\Admin;

use App\Enums\MembershipStatus;
use App\Enums\OrderChannel;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShopPromotion;
use App\Models\User;
use App\Support\Uuid;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class ShopPromotionAdminTest extends TestCase
{
    use ActsAsAdmin, RefreshDatabase;

    private function makeCategory(string $slug = 'hoodies'): ProductCategory
    {
        return ProductCategory::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'sort_order' => 1, 'is_active' => true]);
    }

    private function makeProduct(string $category = 'hoodies', float $price = 200): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test '.$category,
            'slug' => 'test-'.$category.'-'.Str::random(6),
            'price' => $price,
            'category' => $category,
            'gender' => 'unisex',
            'sizes' => [['size' => 'M', 'stock' => 20]],
            'total_stock' => 20,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Hoodie sale',
            'code' => '',
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'category' => 'hoodies',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'is_active' => '1',
        ], $overrides);
    }

    public function test_admin_can_create_an_automatic_category_sale(): void
    {
        $this->makeCategory();

        $this->actingAsAdmin()->post('/admin/shop-promotions/create', $this->payload())
            ->assertRedirect('/admin/shop-promotions');

        $promo = ShopPromotion::query()->firstOrFail();
        $this->assertNull($promo->code);
        $this->assertSame('hoodies', $promo->category);
        $this->assertSame('percentage', $promo->discount_type);
        $this->assertSame(15.0, (float) $promo->discount_value);
    }

    public function test_codes_are_stored_uppercase_and_may_be_fixed_amounts(): void
    {
        $this->makeCategory();

        $this->actingAsAdmin()->post('/admin/shop-promotions/create', $this->payload([
            'code' => 'welcome-50', 'discount_type' => 'fixed', 'discount_value' => 50, 'members_only' => '1', 'min_order_amount' => 200,
        ]))->assertRedirect('/admin/shop-promotions');

        $promo = ShopPromotion::query()->firstOrFail();
        $this->assertSame('WELCOME-50', $promo->code);
        $this->assertSame('fixed', $promo->discount_type);
        $this->assertTrue($promo->members_only);
        $this->assertSame(200.0, (float) $promo->min_order_amount);
    }

    public function test_an_automatic_sale_is_always_a_percentage(): void
    {
        $this->makeCategory();

        $this->actingAsAdmin()->post('/admin/shop-promotions/create', $this->payload(['discount_type' => 'fixed']))
            ->assertRedirect('/admin/shop-promotions');

        $this->assertSame('percentage', ShopPromotion::query()->firstOrFail()->discount_type);
    }

    public function test_a_full_or_over_100_percent_discount_is_rejected(): void
    {
        $this->makeCategory();
        $admin = $this->makeAdminUser();

        foreach ([100, 150] as $value) {
            $this->actingAsAdmin($admin)->post('/admin/shop-promotions/create', $this->payload(['discount_value' => $value]))
                ->assertSessionHasErrors('discount_value');
        }

        $this->assertDatabaseCount('shop_promotions', 0);
    }

    public function test_code_must_be_unique_and_well_formed(): void
    {
        $this->makeCategory();
        $admin = $this->makeAdminUser();

        $this->actingAsAdmin($admin)->post('/admin/shop-promotions/create', $this->payload(['code' => 'ONE']))
            ->assertRedirect('/admin/shop-promotions');
        $this->actingAsAdmin($admin)->post('/admin/shop-promotions/create', $this->payload(['code' => 'one']))
            ->assertSessionHasErrors('code');
        $this->actingAsAdmin($admin)->post('/admin/shop-promotions/create', $this->payload(['code' => 'has space']))
            ->assertSessionHasErrors('code');
    }

    public function test_end_date_cannot_precede_start_and_category_must_exist(): void
    {
        $this->actingAsAdmin()->post('/admin/shop-promotions/create', $this->payload([
            'category' => 'ghost', 'ends_at' => now()->subDay()->toDateString(),
        ]))->assertSessionHasErrors(['category', 'ends_at']);
    }

    public function test_edit_and_delete(): void
    {
        $this->makeCategory();
        $admin = $this->makeAdminUser();
        $this->actingAsAdmin($admin)->post('/admin/shop-promotions/create', $this->payload(['code' => 'EDITME']));
        $promo = ShopPromotion::query()->firstOrFail();

        // Saving a promo with its own code must not trip the unique rule.
        $this->actingAsAdmin($admin)->post("/admin/shop-promotions/{$promo->id}/edit", $this->payload(['code' => 'EDITME', 'discount_value' => 25]))
            ->assertRedirect('/admin/shop-promotions');
        $this->assertSame(25.0, (float) $promo->fresh()->discount_value);

        $this->actingAsAdmin($admin)->get('/admin/shop-promotions')->assertOk()->assertSee('EDITME');
        $this->actingAsAdmin($admin)->get("/admin/shop-promotions/{$promo->id}/edit")->assertOk();

        $this->actingAsAdmin($admin)->post("/admin/shop-promotions/{$promo->id}/delete")->assertRedirect('/admin/shop-promotions');
        $this->assertDatabaseCount('shop_promotions', 0);
    }

    public function test_deleting_a_promotion_keeps_the_discount_on_past_orders(): void
    {
        $promo = ShopPromotion::query()->create([
            'name' => 'Gone', 'code' => 'GONE', 'discount_type' => 'percentage', 'discount_value' => 10,
            'starts_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addDay()->toDateString(), 'is_active' => true,
        ]);
        $order = Order::query()->create([
            'order_number' => 'LFS-X-1', 'customer_name' => 'A', 'customer_email' => 'a@b.c', 'subtotal' => 100,
            'total' => 90, 'discount_amount' => 10, 'promo_code' => 'GONE', 'shop_promotion_id' => $promo->id, 'status' => 'paid',
        ]);

        $promo->delete();

        $order->refresh();
        $this->assertSame(10.0, (float) $order->discount_amount);
        $this->assertSame('GONE', $order->promo_code);
        $this->assertNull($order->shop_promotion_id);
    }

    public function test_read_only_auditor_can_view_but_not_change_shop_promotions(): void
    {
        $promo = ShopPromotion::query()->create([
            'name' => 'Existing', 'code' => 'KEEP', 'discount_type' => 'percentage', 'discount_value' => 10,
            'starts_at' => now()->toDateString(), 'ends_at' => now()->addWeek()->toDateString(),
        ]);
        $auditor = $this->makeAdminUser(['role' => \App\Enums\AdminRole::ReadOnlyAuditor]);

        $this->actingAsAdmin($auditor)->get('/admin/shop-promotions')->assertOk();
        $this->actingAsAdmin($auditor)->post('/admin/shop-promotions/create', $this->payload())->assertForbidden();
        $this->actingAsAdmin($auditor)->post("/admin/shop-promotions/{$promo->id}/edit", $this->payload(['name' => 'Hacked']))->assertForbidden();
        $this->actingAsAdmin($auditor)->post("/admin/shop-promotions/{$promo->id}/delete")->assertForbidden();

        $this->assertDatabaseCount('shop_promotions', 1);
        $this->assertSame('Existing', $promo->fresh()->name);
    }

    public function test_roles_without_promotions_access_are_denied(): void
    {
        $finance = $this->makeAdminUser(['role' => \App\Enums\AdminRole::Finance]);

        $this->actingAsAdmin($finance)->get('/admin/shop-promotions')->assertForbidden();
        $this->actingAsAdmin($finance)->post('/admin/shop-promotions/create', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('shop_promotions', 0);
    }

    public function test_html_in_a_promotion_name_is_escaped_on_the_index(): void
    {
        ShopPromotion::query()->create([
            'name' => '<script>alert(1)</script>', 'code' => 'XSS1', 'discount_type' => 'percentage', 'discount_value' => 10,
            'starts_at' => now()->toDateString(), 'ends_at' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAsAdmin()->get('/admin/shop-promotions')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ── In-person sales ──────────────────────────────────────────────────────

    private function salePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Walk In',
            'customer_phone' => '0971234567',
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'size' => 'M', 'qty' => 1, 'unit_price' => '']],
        ], $overrides);
    }

    private function makeCode(array $overrides = []): ShopPromotion
    {
        return ShopPromotion::query()->create(array_merge([
            'name' => 'Code', 'code' => 'SAVE10', 'discount_type' => 'percentage', 'discount_value' => 10,
            'starts_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addDay()->toDateString(), 'is_active' => true,
        ], $overrides));
    }

    public function test_offline_sale_with_a_code_records_the_discount_on_the_receipt_and_report(): void
    {
        $product = $this->makeProduct('hoodies', 200);
        $this->makeCode();
        $admin = $this->makeAdminUser();

        $this->actingAsAdmin($admin)->post('/admin/orders/sales', $this->salePayload($product, ['promo_code' => 'save10']))
            ->assertSessionHasNoErrors();

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderChannel::Offline, $order->channel);
        $this->assertSame(200.0, (float) $order->subtotal);
        $this->assertSame(20.0, (float) $order->discount_amount);
        $this->assertSame(180.0, (float) $order->total);
        $this->assertSame('SAVE10', $order->promo_code);

        $this->actingAsAdmin($admin)->get("/admin/orders/{$order->id}/receipt")
            ->assertOk()->assertSee('Discount (SAVE10)', false);
        $this->actingAsAdmin($admin)->get('/admin/orders/sales')
            ->assertOk()->assertSee('Discounts given');
        $this->actingAsAdmin($admin)->get('/admin/orders/'.$order->id)
            ->assertOk()->assertSee('SAVE10');

        $csv = $this->actingAsAdmin($admin)->get('/admin/orders/sales/export')->streamedContent();
        $this->assertStringContainsString('Promo code', $csv);
        $this->assertStringContainsString('SAVE10', $csv);
    }

    public function test_offline_sale_applies_automatic_sales_and_skips_hand_priced_lines(): void
    {
        $product = $this->makeProduct('hoodies', 200);
        $this->makeCode(['code' => null, 'name' => 'Sale', 'discount_value' => 50]);

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product))
            ->assertSessionHasNoErrors();
        $this->assertSame(100.0, (float) Order::query()->firstOrFail()->total);

        Order::query()->delete();
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [['product_id' => $product->id, 'size' => 'M', 'qty' => 1, 'unit_price' => '150']],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(150.0, (float) Order::query()->firstOrFail()->total);
    }

    public function test_offline_sale_with_a_bad_code_is_rejected_and_keeps_stock(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['promo_code' => 'NOPE']))
            ->assertSessionHasErrors('promo_code');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(20, $product->fresh()->total_stock);
    }

    public function test_members_only_code_in_person_needs_an_active_member_email(): void
    {
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
        $product = $this->makeProduct();
        $this->makeCode(['code' => 'MEMBER', 'members_only' => true]);
        $user = User::factory()->create(['email' => 'member@example.com']);
        Membership::query()->create([
            'id' => Uuid::v4(), 'user_id' => $user->id, 'membership_number' => '91234', 'public_token' => Uuid::v4(),
            'status' => MembershipStatus::Active, 'start_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addMonths(11)->toDateString(), 'current_plan_id' => MembershipPlan::query()->first()->id,
            'approval_status' => 'approved', 'joined_at' => now()->subMonth(),
        ]);
        $admin = $this->makeAdminUser();

        $this->actingAsAdmin($admin)->post('/admin/orders/sales', $this->salePayload($product, ['promo_code' => 'MEMBER']))
            ->assertSessionHasErrors('promo_code');

        $this->actingAsAdmin($admin)->post('/admin/orders/sales', $this->salePayload($product, ['promo_code' => 'MEMBER', 'member_email' => 'stranger@example.com']))
            ->assertSessionHasErrors('member_email');

        $this->actingAsAdmin($admin)->post('/admin/orders/sales', $this->salePayload($product, ['promo_code' => 'MEMBER', 'member_email' => 'Member@Example.com']))
            ->assertSessionHasNoErrors();
        $this->assertSame(180.0, (float) Order::query()->firstOrFail()->total);
    }
}
