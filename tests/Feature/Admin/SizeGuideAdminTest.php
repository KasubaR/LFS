<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\SizeGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class SizeGuideAdminTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Shorts',
            'note' => 'Measured flat, in cm.',
            'columns' => ['Waist (cm)', 'Inseam (cm)'],
            'rows' => [['S', '71–76', '18'], ['M', '76–81', '19']],
        ], $overrides);
    }

    private function makeProduct(?int $guideId, string $slug = 'tee'): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tee',
            'slug' => $slug,
            'price' => 100,
            'category' => 'other',
            'gender' => 'unisex',
            'is_active' => true,
            'size_guide_id' => $guideId,
        ]);
    }

    public function test_migration_seeds_the_default_guide(): void
    {
        $guide = SizeGuide::query()->firstOrFail();

        $this->assertSame('Standard apparel', $guide->name);
        $this->assertSame(['Chest (cm)', 'Waist (cm)', 'Hips (cm)'], $guide->columns);
        $this->assertCount(5, $guide->rows);
    }

    public function test_admin_can_create_a_guide(): void
    {
        $this->actingAsAdmin()->post('/admin/products/size-guides', $this->payload())
            ->assertRedirect('/admin/products/size-guides');

        $guide = SizeGuide::query()->where('name', 'Shorts')->firstOrFail();
        $this->assertSame(['Waist (cm)', 'Inseam (cm)'], $guide->columns);
        $this->assertSame([['S', '71–76', '18'], ['M', '76–81', '19']], $guide->rows);
    }

    public function test_rows_without_a_size_are_dropped_and_short_rows_are_padded(): void
    {
        $this->actingAsAdmin()->post('/admin/products/size-guides', $this->payload([
            'rows' => [['S', '71'], ['', '99', '99'], ['M', '76', '19']],
        ]));

        $this->assertSame([['S', '71', ''], ['M', '76', '19']], SizeGuide::query()->where('name', 'Shorts')->firstOrFail()->rows);
    }

    public function test_a_guide_needs_a_name_a_column_and_at_least_one_sized_row(): void
    {
        $this->actingAsAdmin();
        $before = SizeGuide::query()->count();

        $this->post('/admin/products/size-guides', $this->payload(['name' => '']))->assertSessionHasErrors('name');
        $this->post('/admin/products/size-guides', $this->payload(['columns' => []]))->assertSessionHasErrors('columns');
        $this->post('/admin/products/size-guides', $this->payload(['rows' => [['', '1', '2']]]))->assertSessionHasErrors('rows');

        $this->assertSame($before, SizeGuide::query()->count());
    }

    public function test_admin_can_edit_a_guide(): void
    {
        $guide = SizeGuide::query()->firstOrFail();

        $this->actingAsAdmin()->post("/admin/products/size-guides/{$guide->id}/edit", $this->payload(['name' => 'Renamed']))
            ->assertRedirect('/admin/products/size-guides');

        $this->assertSame('Renamed', $guide->fresh()->name);
        $this->assertCount(2, $guide->fresh()->rows);
    }

    public function test_a_guide_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $used = SizeGuide::query()->firstOrFail();
        $this->makeProduct($used->id);
        $spare = SizeGuide::query()->create(['name' => 'Spare', 'columns' => ['Chest'], 'rows' => [['S', '1']]]);

        $this->actingAsAdmin()->post("/admin/products/size-guides/{$used->id}/delete")->assertSessionHas('flash.error');
        $this->assertDatabaseHas('size_guides', ['id' => $used->id]);

        $this->actingAsAdmin()->post("/admin/products/size-guides/{$spare->id}/delete");
        $this->assertDatabaseMissing('size_guides', ['id' => $spare->id]);
    }

    public function test_product_can_be_given_and_then_lose_a_size_guide(): void
    {
        $guide = SizeGuide::query()->firstOrFail();
        $product = $this->makeProduct(null);
        $body = ['name' => 'Tee', 'price' => '10', 'category' => 'other', 'gender' => 'unisex'];

        $this->actingAsAdmin()->post("/admin/products/{$product->id}", $body + ['sizeGuideId' => (string) $guide->id]);
        $this->assertSame($guide->id, $product->fresh()->size_guide_id);

        $this->actingAsAdmin()->post("/admin/products/{$product->id}", $body + ['sizeGuideId' => '']);
        $this->assertNull($product->fresh()->size_guide_id);
    }

    public function test_product_cannot_use_a_guide_that_does_not_exist(): void
    {
        $this->actingAsAdmin()->post('/admin/products', [
            'name' => 'Tee', 'price' => '10', 'category' => 'other', 'gender' => 'unisex', 'sizeGuideId' => '9999',
        ])->assertOk()->assertSee('Please choose a valid size guide.');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_product_page_shows_its_own_guide_and_hides_it_when_there_is_none(): void
    {
        $guide = SizeGuide::query()->create([
            'name' => 'Shorts', 'note' => 'Shorts note here.', 'columns' => ['Inseam (cm)'], 'rows' => [['S', '18']],
        ]);
        $this->makeProduct($guide->id, 'with-guide');
        $this->makeProduct(null, 'no-guide');

        $this->get('/shop/product/with-guide')->assertOk()
            ->assertSee('Shorts note here.', false)
            ->assertSee('Inseam (cm)', false)
            ->assertDontSee('Chest (cm)', false)
            ->assertSee('id="size-guide-modal"', false);

        $this->get('/shop/product/no-guide')->assertOk()
            ->assertDontSee('Size Guide', false)
            ->assertDontSee('id="size-guide-modal"', false);
    }
}
