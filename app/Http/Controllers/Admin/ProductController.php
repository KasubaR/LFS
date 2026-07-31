<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Response;

use App\Enums\ProductCategory;
use App\Http\Controllers\Controller;
use App\Services\ProductService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use Throwable;

class ProductController extends Controller
{
    /** @var list<string> */
    private const GENDER_OPTIONS = ['male', 'female', 'unisex'];

    public function __construct(
        private readonly ProductService $productService,
    ) {}

    public function index(Request $request): View
    {
        $page = max(1, (int) $request->query('page', 1));
        $category = $request->query('category', '');
        $search = $request->query('search', '');

        $opts = ['sort' => 'latest', 'page' => $page, 'limit' => 20];
        if ($category !== '') {
            $opts['category'] = $category;
        }

        $products = [];
        $total = 0;
        $pages = 1;
        try {
            ['products' => $products, 'total' => $total, 'pages' => $pages]
                = $this->productService->getProducts($opts, ['admin' => true]);
        } catch (Throwable) {
        }

        $term = strtolower(trim($search));
        if ($term !== '') {
            $products = array_values(array_filter($products, fn (array $p): bool => str_contains(strtolower($p['name'] ?? ''), $term)
                || str_contains(strtolower($p['description'] ?? ''), $term)));
        }

        return view('admin.products.list', [
            'pageTitle' => 'Products',
            'activePage' => 'products',
            'products' => $products,
            'total' => $total,
            'pages' => $pages,
            'currentPage' => $page,
            'filters' => ['category' => $category, 'search' => $search],
            'PRODUCT_CATEGORIES' => ProductCategory::ALL,
            'formatPrice' => fn ($v) => 'ZMW '.number_format((float) $v, 2),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.form', [
            'pageTitle' => 'New Product',
            'activePage' => 'products',
            'product' => null,
            'PRODUCT_CATEGORIES' => ProductCategory::ALL,
            'GENDER_OPTIONS' => self::GENDER_OPTIONS,
            'isEdit' => false,
            'error' => null,
        ]);
    }

    public function store(Request $request): View|RedirectResponse
    {
        if ($uploadError = $request->input('_imageUploadError')) {
            return $this->renderFormWithError(null, false, 'New Product', $request->all(), $uploadError);
        }

        $body = $request->all();
        if ($error = $this->validateProductBody($body)) {
            return $this->renderFormWithError(null, false, 'New Product', $body, $error);
        }

        $data = $this->buildProductData($body);
        $uploadedImages = (array) $request->input('_productImages', []);
        if ($uploadedImages !== []) {
            $data['images'] = $uploadedImages;
            $data['thumbnail'] = $uploadedImages[0];
        }

        try {
            $created = $this->productService->createProduct($data);
            $uploadKey = $request->input('_productUploadKey');
            if ($created && ! empty($created['id']) && $uploadKey && str_starts_with((string) $uploadKey, 'tmp-')) {
                $this->renameUploadDir((string) $uploadKey, (string) $created['id'], $data);
            }

            return redirect('/admin/products');
        } catch (Throwable $e) {
            return $this->renderFormWithError(null, false, 'New Product', $body, $e->getMessage() ?: 'Could not create product. Please try again.');
        }
    }

    public function edit(string $id): View|RedirectResponse
    {
        $product = $this->productService->getProductById($id, ['admin' => true]);
        if (! $product) {
            return redirect('/admin/products');
        }

        return view('admin.products.form', [
            'pageTitle' => 'Edit Product',
            'activePage' => 'products',
            'product' => $product,
            'PRODUCT_CATEGORIES' => ProductCategory::ALL,
            'GENDER_OPTIONS' => self::GENDER_OPTIONS,
            'isEdit' => true,
            'error' => null,
        ]);
    }

    public function update(Request $request, string $id): View|RedirectResponse
    {
        if ($uploadError = $request->input('_imageUploadError')) {
            $existing = $this->safeGetById($id);
            $merged = array_merge($existing ?? [], $request->all());

            return $this->renderFormWithError($merged, true, $merged['name'] ?? 'Edit', $merged, $uploadError);
        }

        $existing = $this->productService->getProductById($id, ['admin' => true]);
        if (! $existing) {
            return redirect('/admin/products');
        }

        $body = $request->all();
        if ($error = $this->validateProductBody($body)) {
            $merged = array_merge($existing, $body);

            return $this->renderFormWithError($merged, true, 'Edit Product', $merged, $error);
        }

        $data = $this->buildProductData($body);
        $uploadedImages = (array) $request->input('_productImages', []);
        if ($uploadedImages !== []) {
            $existingImages = is_array($existing['images'] ?? null) ? $existing['images'] : [];
            $data['images'] = array_merge($existingImages, $uploadedImages);
            $data['thumbnail'] = $existing['thumbnail'] ?? $uploadedImages[0];
        }

        try {
            $this->productService->updateProduct($id, $data);

            return redirect('/admin/products');
        } catch (Throwable $e) {
            $merged = array_merge($existing, $body);

            return $this->renderFormWithError($merged, true, 'Edit Product', $merged, $e->getMessage() ?: 'Could not update product. Please try again.');
        }
    }

    public function preview(string $id): View|RedirectResponse
    {
        $product = $this->productService->getProductById($id, ['admin' => true]);
        if (! $product) {
            return redirect('/admin/products');
        }

        $related = $this->productService->findRelatedByCategory($product['category'], $product['id'], 4);

        return view('pages.productDetails', [
            'title' => $product['name'].' — LFS Shop (Preview)',
            'description' => $product['shortDescription']
                ?? substr($product['description'] ?? '', 0, 155)
                ?: '',
            'bodyClass' => 'page-no-hero',
            'product' => $product,
            'related' => $related,
            'cartCount' => 0,
            'siteUrl' => config('app.url', 'https://www.lfszambia.run'),
            'formatPrice' => fn (float $amount): string => 'ZMW '.number_format($amount, 2),
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/shop.css').'">'
                .'<link rel="stylesheet" href="'.asset('css/productDetails.css').'">',
            'extraScripts' => '<script src="'.asset('js/productDetails.js').'"></script>',
        ]);
    }

    public function destroy(string $id): RedirectResponse
    {
        try {
            $this->productService->updateProduct($id, ['isActive' => false]);
        } catch (Throwable $e) {
            Log::error('[LFS Admin] ProductController::destroy — '.$e->getMessage());
        }

        return redirect('/admin/products');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function validateProductBody(array $body): ?string
    {
        if (empty($body['name']) || ! isset($body['price']) || $body['price'] === ''
            || empty($body['category']) || empty($body['gender'])) {
            return 'Name, price, category and gender are required.';
        }
        $numericPrice = (float) $body['price'];
        if ($numericPrice < 0 || ! is_finite($numericPrice)) {
            return 'Price must be a non-negative number.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function buildProductData(array $body): array
    {
        return [
            'name' => trim($body['name']),
            'slug' => ! empty($body['slug']) ? $this->slugify($body['slug']) : $this->slugify($body['name']),
            'price' => (float) $body['price'],
            'comparePrice' => ($body['comparePrice'] ?? '') !== '' ? (float) $body['comparePrice'] : null,
            'description' => $body['description'] ?? '',
            'shortDescription' => $body['shortDescription'] ?? '',
            'category' => $body['category'],
            'gender' => $body['gender'],
            'tags' => $this->parseTags($body['tags'] ?? ''),
            'sizes' => $this->parseSizes($body['sizes'] ?? ''),
            'totalStock' => ($body['totalStock'] ?? '') !== '' ? (int) $body['totalStock'] : 0,
            'featured' => $this->normaliseCheckbox($body['featured'] ?? null),
            'isActive' => $this->normaliseCheckbox($body['isActive'] ?? null),
            'sortOrder' => ($body['sortOrder'] ?? '') !== '' ? (int) $body['sortOrder'] : 0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $product
     */
    private function renderFormWithError(?array $product, bool $isEdit, string $pageTitle, array $formData, string $error): View
    {
        return view('admin.products.form', [
            'pageTitle' => $pageTitle,
            'activePage' => 'products',
            'product' => $product ?? $formData,
            'PRODUCT_CATEGORIES' => ProductCategory::ALL,
            'GENDER_OPTIONS' => self::GENDER_OPTIONS,
            'isEdit' => $isEdit,
            'error' => $error,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Products', 'url' => '/admin/products'],
                ['label' => $pageTitle],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function parseTags(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(fn ($t) => strtolower(trim($t)), explode(',', $raw))));
    }

    /**
     * @return list<array{size: string, stock: int}>
     */
    private function parseSizes(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $result = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            [$size, $stockStr] = array_pad(array_map('trim', explode(':', $entry, 2)), 2, '0');
            if ($size !== '') {
                $result[] = ['size' => $size, 'stock' => max(0, (int) $stockStr)];
            }
        }

        return $result;
    }

    private function normaliseCheckbox(mixed $value): bool
    {
        return $value === 'on' || $value === 'true' || $value === true || $value === '1';
    }

    private function slugify(string $str): string
    {
        $str = strtolower(trim($str));
        $str = preg_replace('/[^a-z0-9\s\-]/', '', $str);
        $str = preg_replace('/[\s\-]+/', '-', $str);

        return trim($str, '-');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renameUploadDir(string $oldKey, string $newKey, array &$data): void
    {
        $uploadRoot = public_path('uploads/products');
        $oldDir = $uploadRoot.'/'.$oldKey;
        $newDir = $uploadRoot.'/'.$newKey;

        if (is_dir($oldDir)) {
            try {
                rename($oldDir, $newDir);
            } catch (Throwable) {
                return;
            }
        }

        $rewrite = fn (string $url): string => str_replace(
            '/uploads/products/'.$oldKey.'/',
            '/uploads/products/'.$newKey.'/',
            $url
        );

        $images = array_map($rewrite, (array) ($data['images'] ?? []));
        $thumbnail = isset($data['thumbnail']) ? $rewrite($data['thumbnail']) : ($images[0] ?? null);

        try {
            $this->productService->updateProduct($newKey, [
                'images' => $images,
                'thumbnail' => $thumbnail,
            ]);
        } catch (Throwable $e) {
            Log::error('[LFS Admin] ProductController::renameUploadDir — '.$e->getMessage());
        }
    }

    private function safeGetById(string $id): ?array
    {
        try {
            return $this->productService->getProductById($id, ['admin' => true]);
        } catch (Throwable) {
            return null;
        }
    }
}
