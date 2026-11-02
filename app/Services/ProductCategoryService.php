<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProductCategoryService
{
    /**
     * Every category in display order, each with how many products use it.
     *
     * @return list<array{id: int, slug: string, name: string, sortOrder: int, isActive: bool, productCount: int}>
     */
    public function getAllWithCounts(): array
    {
        $counts = Product::query()
            ->selectRaw('category, COUNT(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        return ProductCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $c): array => [
                'id' => (int) $c->id,
                'slug' => $c->slug,
                'name' => $c->name,
                'sortOrder' => (int) $c->sort_order,
                'isActive' => (bool) $c->is_active,
                'productCount' => (int) ($counts[$c->slug] ?? 0),
            ])
            ->all();
    }

    /**
     * slug => name for the product form. Active categories only, plus
     * $keep so a product already in a hidden category can still be edited.
     *
     * @return array<string, string>
     */
    public function formOptions(?string $keep = null): array
    {
        return ProductCategory::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q2) => $q2->orWhere('slug', $keep)))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();
    }

    /**
     * slug => name for a filter: categories that have products (visible ones
     * only for the shop, any for the admin), plus the selected one.
     *
     * @return array<string, string>
     */
    public function filterOptions(bool $includeHidden, ?string $selected = null): array
    {
        $used = Product::query()
            ->when(! $includeHidden, fn ($q) => $q->where('is_active', true))
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->all();

        return ProductCategory::query()
            ->when(! $includeHidden, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (ProductCategory $c): bool => $c->slug === $selected || in_array($c->slug, $used, true))
            ->pluck('name', 'slug')
            ->all();
    }

    public function isValidSlug(string $slug): bool
    {
        return ProductCategory::query()->where('slug', $slug)->exists();
    }

    /**
     * A product may be saved into an active category, or keep its current one.
     */
    public function isAssignable(string $slug, ?string $current = null): bool
    {
        return ProductCategory::query()
            ->where('slug', $slug)
            ->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q2) => $q2->orWhere('slug', $current)))
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $category = ProductCategory::query()->find($id);
        if ($category === null) {
            return null;
        }

        return [
            'id' => (int) $category->id,
            'slug' => $category->slug,
            'name' => $category->name,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
        ];
    }

    /**
     * @param  array{name: string, sort_order?: int, is_active?: bool}  $data
     */
    public function create(array $data): ProductCategory
    {
        $name = trim($data['name']);
        $base = Str::slug($name);
        if ($base === '') {
            throw new InvalidArgumentException('Category name must contain letters or numbers.');
        }

        $slug = $base;
        $i = 2;
        while (ProductCategory::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return ProductCategory::query()->create([
            'slug' => substr($slug, 0, 50),
            'name' => $name,
            'sort_order' => $data['sort_order'] ?? ((int) ProductCategory::query()->max('sort_order') + 1),
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * The slug is deliberately left alone so existing products and
     * /shop?category= links keep working after a rename.
     *
     * @param  array{name: string, sort_order?: int, is_active?: bool}  $data
     */
    public function update(int $id, array $data): bool
    {
        $category = ProductCategory::query()->find($id);
        if ($category === null) {
            return false;
        }

        $category->update([
            'name' => trim($data['name']),
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);

        return true;
    }

    /**
     * @return int|null  null once deleted, otherwise how many products still use it
     */
    public function delete(int $id): ?int
    {
        $category = ProductCategory::query()->find($id);
        if ($category === null) {
            return null;
        }

        $inUse = Product::query()->where('category', $category->slug)->count();
        if ($inUse > 0) {
            return $inUse;
        }

        $category->delete();

        return null;
    }
}
