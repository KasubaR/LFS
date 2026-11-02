<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class ProductCategoryAdminTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    private function makeProduct(string $category, bool $active = true): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Item '.uniqid(),
            'slug' => 'item-'.uniqid(),
            'price' => 100,
            'category' => $category,
            'gender' => 'unisex',
            'is_active' => $active,
        ]);
    }

    public function test_migration_seeds_the_original_categories(): void
    {
        $this->assertSame(
            ['running-kits', 't-shirts', 'caps', 'shorts', 'accessories', 'other'],
            ProductCategory::query()->orderBy('sort_order')->pluck('slug')->all()
        );
    }

    public function test_admin_can_add_a_category_with_a_generated_slug(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/products/categories', ['name' => 'Hoodies & Jackets']);

        $response->assertRedirect('/admin/products/categories');
        $this->assertDatabaseHas('product_categories', ['name' => 'Hoodies & Jackets', 'slug' => 'hoodies-jackets']);
    }

    public function test_duplicate_names_get_a_unique_slug(): void
    {
        $this->actingAsAdmin()->post('/admin/products/categories', ['name' => 'Caps']);

        $this->assertDatabaseHas('product_categories', ['slug' => 'caps-2']);
    }

    public function test_renaming_keeps_the_slug_and_existing_products(): void
    {
        $category = ProductCategory::query()->where('slug', 'caps')->firstOrFail();
        $product = $this->makeProduct('caps');

        $this->actingAsAdmin()->post("/admin/products/categories/{$category->id}/edit", [
            'name' => 'Headwear',
            'is_active' => '1',
        ])->assertRedirect('/admin/products/categories');

        $category->refresh();
        $this->assertSame('Headwear', $category->name);
        $this->assertSame('caps', $category->slug);
        $this->assertSame('caps', $product->fresh()->category);
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $category = ProductCategory::query()->where('slug', 'caps')->firstOrFail();
        $this->makeProduct('caps');

        $response = $this->actingAsAdmin()->post("/admin/products/categories/{$category->id}/delete");

        $response->assertRedirect('/admin/products/categories');
        $response->assertSessionHas('flash.error');
        $this->assertDatabaseHas('product_categories', ['slug' => 'caps']);
    }

    public function test_an_unused_category_can_be_deleted(): void
    {
        $category = ProductCategory::query()->where('slug', 'shorts')->firstOrFail();

        $this->actingAsAdmin()->post("/admin/products/categories/{$category->id}/delete");

        $this->assertDatabaseMissing('product_categories', ['slug' => 'shorts']);
    }

    public function test_product_form_only_offers_active_categories(): void
    {
        ProductCategory::query()->where('slug', 'shorts')->update(['is_active' => false]);

        $html = $this->actingAsAdmin()->get('/admin/products/create')->assertOk()->getContent();

        $this->assertStringContainsString('value="caps"', $html);
        $this->assertStringNotContainsString('value="shorts"', $html);
    }

    public function test_product_cannot_be_saved_into_an_unknown_or_hidden_category(): void
    {
        ProductCategory::query()->where('slug', 'shorts')->update(['is_active' => false]);

        foreach (['not-a-category', 'shorts'] as $slug) {
            $this->actingAsAdmin()->post('/admin/products', [
                'name' => 'Test', 'price' => '10', 'category' => $slug, 'gender' => 'unisex',
            ])->assertOk()->assertSee('Please choose a valid category.');
        }

        $this->assertDatabaseCount('products', 0);
    }

    public function test_product_in_a_now_hidden_category_can_still_be_edited(): void
    {
        $product = $this->makeProduct('shorts');
        ProductCategory::query()->where('slug', 'shorts')->update(['is_active' => false]);

        $this->actingAsAdmin()->post("/admin/products/{$product->id}", [
            'name' => 'Renamed', 'price' => '10', 'category' => 'shorts', 'gender' => 'unisex',
        ])->assertRedirect('/admin/products');

        $this->assertSame('Renamed', $product->fresh()->name);
    }

    public function test_shop_filter_lists_only_categories_with_visible_products(): void
    {
        $this->makeProduct('caps');
        $this->makeProduct('shorts', false);

        $html = $this->get('/shop')->assertOk()->getContent();

        $this->assertStringContainsString('value="caps"', $html);
        $this->assertStringNotContainsString('value="shorts"', $html);
        $this->assertStringNotContainsString('value="t-shirts"', $html);
    }
}
