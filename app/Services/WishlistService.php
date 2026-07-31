<?php

namespace App\Services;

use App\Models\Product;
use App\Models\WishlistItem;

class WishlistService
{
    public function __construct(
        private readonly ProductService $productService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return WishlistItem::query()
            ->where('user_id', $userId)
            ->with('product')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (WishlistItem $item) => $item->product !== null)
            ->map(fn (WishlistItem $item) => $this->toWishlistProduct($item->product, (string) $item->created_at))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function productIdsForUser(int $userId): array
    {
        return WishlistItem::query()
            ->where('user_id', $userId)
            ->pluck('product_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function isInWishlist(int $userId, string $productId): bool
    {
        return WishlistItem::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->exists();
    }

    public function add(int $userId, string $productId): bool
    {
        $product = Product::query()
            ->whereKey($productId)
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            return false;
        }

        WishlistItem::query()->firstOrCreate([
            'user_id' => $userId,
            'product_id' => $productId,
        ]);

        return true;
    }

    public function remove(int $userId, string $productId): bool
    {
        $deleted = WishlistItem::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->delete();

        return $deleted > 0;
    }

    public function countForUser(int $userId): int
    {
        return WishlistItem::query()->where('user_id', $userId)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function toWishlistProduct(Product $product, string $addedAt): array
    {
        $mapped = $this->productService->findById((string) $product->id) ?? [
            '_id' => (string) $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => (float) $product->price,
            'comparePrice' => $product->compare_price !== null ? (float) $product->compare_price : null,
            'thumbnail' => $product->thumbnail,
            'images' => $product->images ?? [],
            'category' => $product->category,
            'gender' => $product->gender,
            'sizes' => $product->sizes ?? [],
            'totalStock' => (int) $product->total_stock,
            'featured' => (bool) $product->featured,
        ];

        $mapped['addedAt'] = $addedAt;

        return $mapped;
    }
}
