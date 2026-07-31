<?php

namespace App\Services;

use App\Models\Product;
use App\Support\Uuid;
use Illuminate\Database\Eloquent\Builder;

class ProductService
{
    /**
     * @param  array<string, mixed>  $opts
     * @param  array<string, mixed>  $options
     * @return array{products: list<array<string, mixed>>, total: int, page: int, limit: int, pages: int}
     */
    public function getProducts(array $opts = [], array $options = []): array
    {
        $admin = (bool) ($options['admin'] ?? false);
        $category = $opts['category'] ?? null;
        $gender = $opts['gender'] ?? null;
        $size = $opts['size'] ?? null;
        $minPrice = isset($opts['minPrice']) && $opts['minPrice'] !== '' ? (float) $opts['minPrice'] : null;
        $maxPrice = isset($opts['maxPrice']) && $opts['maxPrice'] !== '' ? (float) $opts['maxPrice'] : null;
        $sort = $opts['sort'] ?? 'latest';
        $page = max(1, (int) ($opts['page'] ?? 1));
        $limit = max(1, (int) ($opts['limit'] ?? 12));

        $query = $this->buildProductQuery($admin, $category, $gender, $size, $minPrice, $maxPrice);

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $limit));
        $offset = ($page - 1) * $limit;

        $sorted = match ($sort) {
            'popular' => $query->orderByDesc('sort_order'),
            'price-asc' => $query->orderBy('price'),
            'price-desc' => $query->orderByDesc('price'),
            default => $query->orderByDesc('created_at'),
        };

        $products = $sorted
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (Product $product) => $this->toProduct($product))
            ->all();

        return [
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
        ];
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array{products: list<array<string, mixed>>, total: int, page: int, limit: int, pages: int}
     */
    public function findPublic(array $opts = []): array
    {
        return $this->getProducts($opts, ['admin' => false]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function getProductBySlug(string $slug, array $options = []): ?array
    {
        $admin = (bool) ($options['admin'] ?? false);
        $query = Product::query()->where('slug', $slug);

        if (! $admin) {
            $query->where('is_active', true);
        }

        $product = $query->first();

        return $product ? $this->toProduct($product) : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function getProductById(string $id, array $options = []): ?array
    {
        $admin = (bool) ($options['admin'] ?? false);
        $query = Product::query()->whereKey($id);

        if (! $admin) {
            $query->where('is_active', true);
        }

        $product = $query->first();

        return $product ? $this->toProduct($product) : null;
    }

    public function findOneBySlug(string $slug): ?array
    {
        return $this->getProductBySlug($slug, ['admin' => false]);
    }

    public function findById(string $id): ?array
    {
        return $this->getProductById($id, ['admin' => false]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRelatedByCategory(string $category, string $excludeId, int $limit = 4): array
    {
        return Product::query()
            ->where('category', $category)
            ->where('is_active', true)
            ->where('id', '!=', $excludeId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => $this->toProduct($product))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createProduct(array $data): array
    {
        $id = Uuid::v4();

        Product::query()->create([
            'id' => $id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'price' => $data['price'],
            'compare_price' => $data['comparePrice'] ?? null,
            'description' => $data['description'] ?? '',
            'short_description' => $data['shortDescription'] ?? '',
            'images' => $data['images'] ?? [],
            'thumbnail' => $data['thumbnail'] ?? '/images/products/placeholder.webp',
            'category' => $data['category'],
            'gender' => $data['gender'] ?? 'unisex',
            'tags' => $data['tags'] ?? [],
            'sizes' => $data['sizes'] ?? [],
            'total_stock' => (int) ($data['totalStock'] ?? 0),
            'featured' => (bool) ($data['featured'] ?? false),
            'is_active' => ($data['isActive'] ?? true) !== false,
            'sort_order' => (int) ($data['sortOrder'] ?? 0),
        ]);

        return $this->getProductById($id, ['admin' => true]) ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProduct(string $id, array $data): ?array
    {
        $map = [
            'name' => ['name', 'string'],
            'slug' => ['slug', 'string'],
            'price' => ['price', 'float'],
            'comparePrice' => ['compare_price', 'null_float'],
            'description' => ['description', 'string'],
            'shortDescription' => ['short_description', 'string'],
            'images' => ['images', 'json'],
            'thumbnail' => ['thumbnail', 'string'],
            'category' => ['category', 'string'],
            'gender' => ['gender', 'string'],
            'tags' => ['tags', 'json'],
            'sizes' => ['sizes', 'json'],
            'totalStock' => ['total_stock', 'int'],
            'featured' => ['featured', 'bool'],
            'isActive' => ['is_active', 'bool'],
            'sortOrder' => ['sort_order', 'int'],
        ];

        $updates = [];
        foreach ($map as $camel => [$snake, $type]) {
            if (! array_key_exists($camel, $data)) {
                continue;
            }

            $value = $data[$camel];
            $updates[$snake] = match ($type) {
                'string' => (string) $value,
                'float' => (float) $value,
                'null_float' => $value !== null ? (float) $value : null,
                'int' => (int) $value,
                'bool' => (bool) $value,
                'json' => is_array($value) ? $value : [],
                default => $value,
            };
        }

        if ($updates === []) {
            return $this->getProductById($id, ['admin' => true]);
        }

        $updates['updated_at'] = now();
        $affected = Product::query()->whereKey($id)->update($updates);

        return $affected > 0
            ? $this->getProductById($id, ['admin' => true])
            : null;
    }

    public function deleteProduct(string $id): void
    {
        Product::query()->whereKey($id)->delete();
    }

    public function sanitiseImageUrl(mixed $url, string $fallback = ''): string
    {
        $value = trim((string) ($url ?? ''));

        return $value !== '' ? $value : $fallback;
    }

    private function buildProductQuery(
        bool $admin,
        mixed $category,
        mixed $gender,
        mixed $size,
        ?float $minPrice,
        ?float $maxPrice
    ): Builder {
        $query = Product::query();

        if (! $admin) {
            $query->where('is_active', true);
        }
        if ($category !== null && $category !== '') {
            $query->where('category', $category);
        }
        if ($gender !== null && $gender !== '') {
            $query->where('gender', $gender);
        }
        if ($minPrice !== null) {
            $query->where('price', '>=', $minPrice);
        }
        if ($maxPrice !== null) {
            $query->where('price', '<=', $maxPrice);
        }
        if ($size !== null && $size !== '') {
            $query->whereRaw('JSON_CONTAINS(sizes, ?)', [json_encode(['size' => (string) $size])]);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function toProduct(Product $product): array
    {
        return [
            '_id' => $product->id,
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => (float) $product->price,
            'comparePrice' => $product->compare_price !== null ? (float) $product->compare_price : null,
            'description' => $product->description ?? '',
            'shortDescription' => $product->short_description ?? '',
            'images' => $product->images ?? [],
            'thumbnail' => $this->sanitiseImageUrl(
                $product->thumbnail,
                '/images/products/placeholder.webp'
            ),
            'category' => $product->category,
            'gender' => $product->gender ?? 'unisex',
            'tags' => $product->tags ?? [],
            'sizes' => $product->sizes ?? [],
            'totalStock' => (int) ($product->total_stock ?? 0),
            'featured' => (bool) $product->featured,
            'isActive' => (bool) $product->is_active,
            'sortOrder' => (int) ($product->sort_order ?? 0),
            'createdAt' => (string) $product->created_at,
            'updatedAt' => (string) $product->updated_at,
        ];
    }
}
