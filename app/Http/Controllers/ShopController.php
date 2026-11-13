<?php

namespace App\Http\Controllers;

use App\Exceptions\PromoCodeException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Requests\AddToCartRequest;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use App\Services\ShopPromotionService;
use App\Services\SizeGuideService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ShopController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly ProductService $productService,
        private readonly ProductCategoryService $categoryService,
        private readonly SizeGuideService $sizeGuideService,
        private readonly ShopPromotionService $promotions,
    ) {}

    public function index(Request $request): View
    {
        $category = $request->query('category', '');
        $gender = $request->query('gender', '');
        $size = $request->query('size', '');
        $sort = $request->query('sort', 'latest');
        $page = max(1, (int) $request->query('page', 1));
        $minPrice = $request->query('minPrice', '');
        $maxPrice = $request->query('maxPrice', '');

        $filters = ['sort' => $sort, 'page' => $page, 'limit' => 12];
        if ($category !== '') {
            $filters['category'] = $category;
        }
        if ($gender !== '') {
            $filters['gender'] = $gender;
        }
        if ($size !== '') {
            $filters['size'] = $size;
        }
        if ($minPrice !== '') {
            $filters['minPrice'] = (float) $minPrice;
        }
        if ($maxPrice !== '') {
            $filters['maxPrice'] = (float) $maxPrice;
        }

        ['products' => $products, 'total' => $total, 'pages' => $pages] = ['products' => [], 'total' => 0, 'pages' => 1];
        try {
            ['products' => $products, 'total' => $total, 'pages' => $pages]
                = $this->productService->findPublic($filters);
        } catch (Throwable) {
        }

        return view('pages.shop', [
            'title' => 'Shop LFS Merchandise',
            'description' => 'High-quality running gear and regalia for Lusaka Fitness Squad members.',
            'bodyClass' => 'page-no-hero page-no-nav',
            'hideNavbar' => true,
            'products' => $products,
            'total' => $total,
            'currentPage' => $page,
            'pages' => max(1, (int) ($pages ?? 1)),
            'cartCount' => $this->cartItemCount(),
            'filters' => compact('category', 'gender', 'size', 'sort', 'minPrice', 'maxPrice'),
            'categoryOptions' => $this->categoryFilterOptions((string) $category),
            'genderOptions' => $this->genderFilterOptions(),
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/shop.css').'">',
            'extraScripts' => '<script src="'.asset('js/shop.js').'"></script>',
        ]);
    }

    /**
     * Active categories that have at least one visible product, in the admin's order.
     *
     * @return list<array{value: string, label: string}>
     */
    private function categoryFilterOptions(string $selected): array
    {
        $options = [['value' => '', 'label' => 'All Categories']];

        try {
            foreach ($this->categoryService->filterOptions(false, $selected) as $slug => $name) {
                $options[] = ['value' => (string) $slug, 'label' => $name];
            }
        } catch (Throwable) {
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function genderFilterOptions(): array
    {
        return [
            ['value' => '', 'label' => 'All'],
            ['value' => 'male', 'label' => 'Male'],
            ['value' => 'female', 'label' => 'Female'],
            ['value' => 'unisex', 'label' => 'Unisex'],
        ];
    }

    public function show(string $slug): View|\Illuminate\Http\Response
    {
        $product = null;
        try {
            $product = $this->productService->findOneBySlug($slug);
        } catch (Throwable) {
        }

        if (! $product) {
            return response()->view('pages.404', [
                'title' => 'Product Not Found',
                'description' => 'That product does not exist or is no longer available.',
                'bodyClass' => 'page-no-hero',
            ], 404);
        }

        $related = [];
        try {
            $related = $this->productService->findRelatedByCategory($product['category'], $product['id'], 4);
        } catch (Throwable) {
        }

        $sizeGuide = null;
        try {
            $sizeGuide = $this->sizeGuideService->getById($product['sizeGuideId'] ?? null);
        } catch (Throwable) {
        }

        return view('pages.productDetails', [
            'sizeGuide' => $sizeGuide,
            'title' => $product['name'].' — LFS Shop',
            'description' => $product['shortDescription']
                ?? substr($product['description'] ?? '', 0, 155)
                ?: '',
            'bodyClass' => 'page-no-hero page-no-nav',
            'hideNavbar' => true,
            'product' => $product,
            'related' => $related,
            'cartCount' => $this->cartItemCount(),
            'siteUrl' => config('app.url', 'https://www.lfszambia.run'),
            'formatPrice' => fn (float $amount): string => $this->formatPrice($amount),
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/shop.css').'">'
                .'<link rel="stylesheet" href="'.asset('css/productDetails.css').'">',
            'extraScripts' => '<script src="'.asset('js/productDetails.js').'"></script>',
        ]);
    }

    public function cart(): View
    {
        $cart = $this->pruneUnavailable($this->loadCart());
        ['itemCount' => $itemCount] = $this->cartTotals($cart);
        $pricing = $this->pricing($cart);

        return view('pages.shop-cart', [
            'title' => 'Your Cart — LFS Shop',
            'description' => 'Review your LFS merchandise cart.',
            'bodyClass' => 'page-no-hero',
            'cart' => $this->pricedCart($cart, $pricing),
            'itemCount' => $itemCount,
            'cartCount' => $itemCount,
            'subtotal' => $this->formatPrice($pricing['subtotal']),
            'discount' => $pricing['discount'],
            'discountFormatted' => $this->formatPrice($pricing['discount']),
            'discountLabel' => $this->discountLabel($pricing),
            'total' => $this->formatPrice($pricing['total']),
            'promoCode' => $pricing['code'],
            'promoError' => $pricing['promoError'],
            'showPromoBox' => $pricing['code'] !== null || $this->promotions->hasActiveCodes(),
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/cart.css').'">',
            'extraScripts' => '<script src="'.asset('js/promo.js').'"></script>',
        ]);
    }

    public function checkout(): View|RedirectResponse
    {
        $cart = $this->pruneUnavailable($this->loadCart());
        ['itemCount' => $itemCount] = $this->cartTotals($cart);

        if ($itemCount === 0) {
            return redirect('/shop/cart');
        }

        $pricing = $this->pricing($cart);
        $cart = $this->pricedCart($cart, $pricing);

        $cartItems = array_map(function (array $item): array {
            $price = (float) ($item['price'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);
            $image = $item['image'] ?: '/images/products/placeholder.webp';

            return [
                'key' => $item['key'] ?? '',
                'productId' => $item['productId'] ?? '',
                'name' => $item['name'] ?? '',
                'size' => $item['size'] ?? '',
                'qty' => $qty,
                'price' => $price,
                'subtotal' => (float) ($item['lineNet'] ?? $price * $qty),
                'image' => $image,
            ];
        }, $cart);

        return view('pages.checkout', [
            'title' => 'Checkout — LFS Shop',
            'description' => 'Complete your order for official LFS regalia and gear.',
            'bodyClass' => 'page-no-hero',
            'cartItems' => $cartItems,
            'cartCount' => $itemCount,
            'subtotal' => $this->formatPrice($pricing['subtotal']),
            'discount' => $pricing['discount'],
            'discountFormatted' => $this->formatPrice($pricing['discount']),
            'discountLabel' => $this->discountLabel($pricing),
            'total' => $this->formatPrice($pricing['total']),
            'promoCode' => $pricing['code'],
            'promoError' => $pricing['promoError'],
            'showPromoBox' => $pricing['code'] !== null || $this->promotions->hasActiveCodes(),
            'csrfToken' => csrf_token(),
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/checkout.css').'">',
            'extraScripts' => '<script src="'.asset('js/checkout.js').'"></script>'
                .'<script src="'.asset('js/checkout-lenco.js').'"></script>'
                .'<script src="'.asset('js/promo.js').'"></script>',
        ]);
    }

    public function addToCart(AddToCartRequest $request): JsonResponse|RedirectResponse
    {
        $productId = trim($request->input('productId', ''));
        $size = trim($request->input('size', ''));
        $qty = max(1, (int) $request->input('qty', 1));

        $product = $this->productService->findById($productId);

        if (! $product) {
            return $this->cartErrorResponse($request, 404, 'Product not found.', '/shop');
        }

        $sizeEntry = null;
        foreach ($product['sizes'] as $s) {
            if (($s['size'] ?? '') === $size) {
                $sizeEntry = $s;
                break;
            }
        }

        if ($sizeEntry === null || (int) ($sizeEntry['stock'] ?? 0) <= 0) {
            return $this->cartErrorResponse(
                $request,
                400,
                "Size {$size} is out of stock.",
                '/shop/product/'.$product['slug']
            );
        }

        $cart = $this->loadCart();
        $maxQty = (int) $sizeEntry['stock'];
        $cartKey = $productId.'::'.$size;
        $found = false;

        foreach ($cart as &$item) {
            if ($item['key'] === $cartKey) {
                $item['qty'] = min($item['qty'] + $qty, $maxQty);
                $found = true;
                break;
            }
        }
        unset($item);

        if (! $found) {
            $cart[] = [
                'key' => $cartKey,
                'productId' => (string) $product['id'],
                'slug' => $product['slug'],
                'name' => $product['name'],
                // List price: sales and codes are applied server-side at cart/order time.
                'price' => (float) ($product['listPrice'] ?? $product['price']),
                'image' => $product['thumbnail'] ?: '/images/products/placeholder.webp',
                'size' => $size,
                'qty' => min($qty, $maxQty),
            ];
        }

        $this->saveCart($cart);

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['ok' => true, 'message' => 'Item added to cart.'] + $this->cartPayload($cart));
        }

        return redirect('/shop/cart');
    }

    public function updateCart(Request $request): JsonResponse|RedirectResponse
    {
        $key = $request->input('key', '');
        $newQty = (int) $request->input('qty', 0);
        $cart = $this->loadCart();

        foreach ($cart as $i => $item) {
            if ($item['key'] === $key) {
                if ($newQty <= 0) {
                    array_splice($cart, $i, 1);
                } else {
                    $stock = $this->productService->getAvailableStock(
                        (string) ($item['productId'] ?? ''),
                        (string) ($item['size'] ?? '')
                    );
                    $cart[$i]['qty'] = $stock !== null ? min($newQty, max(1, $stock)) : $newQty;
                }
                break;
            }
        }

        $this->saveCart($cart);

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['ok' => true] + $this->cartPayload($cart));
        }

        return redirect('/shop/cart');
    }

    public function removeFromCart(Request $request): JsonResponse|RedirectResponse
    {
        $key = $request->input('key', '');
        $cart = $this->loadCart();

        foreach ($cart as $i => $item) {
            if ($item['key'] === $key) {
                array_splice($cart, $i, 1);
                break;
            }
        }

        $this->saveCart($cart);

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['ok' => true] + $this->cartPayload($cart));
        }

        return redirect('/shop/cart');
    }

    public function applyPromo(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:40']]);
        $cart = $this->loadCart();
        if ($cart === []) {
            return $this->jsonError('Your cart is empty.', 422);
        }

        try {
            $this->promotions->priceCart($this->pricingLines($cart), (string) $request->input('code'), Auth::user());
        } catch (PromoCodeException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        session(['cart_promo_code' => strtoupper(trim((string) $request->input('code')))]);

        return $this->jsonResponse(['ok' => true] + $this->cartPayload($cart));
    }

    public function removePromo(): JsonResponse
    {
        session()->forget('cart_promo_code');

        return $this->jsonResponse(['ok' => true] + $this->cartPayload($this->loadCart()));
    }

    /**
     * @param  list<array<string, mixed>>  $cart
     * @return list<array{productId: string, qty: int}>
     */
    private function pricingLines(array $cart): array
    {
        return array_map(fn (array $i): array => [
            'productId' => (string) ($i['productId'] ?? ''),
            'qty' => (int) ($i['qty'] ?? 0),
        ], $cart);
    }

    /**
     * Prices the cart with sales and the session's promo code. A code that has
     * stopped being valid (expired, cart changed, membership lapsed) is dropped
     * and reported in promoError rather than silently ignored.
     *
     * @param  list<array<string, mixed>>  $cart
     * @return array<string, mixed>
     */
    private function pricing(array $cart): array
    {
        $lines = $this->pricingLines($cart);
        $error = null;

        try {
            $priced = $this->promotions->priceCart($lines, session('cart_promo_code'), Auth::user());
        } catch (PromoCodeException $e) {
            session()->forget('cart_promo_code');
            $error = $e->getMessage();
            $priced = $this->promotions->priceCart($lines, null, Auth::user());
        }

        $priced['promoError'] = $error;

        return $priced;
    }

    /**
     * Cart items with the effective (post-sale / post-code) prices for display.
     * The stored list price is kept in listPrice.
     *
     * @param  list<array<string, mixed>>  $cart
     * @param  array<string, mixed>  $pricing
     * @return list<array<string, mixed>>
     */
    private function pricedCart(array $cart, array $pricing): array
    {
        foreach ($cart as $i => $item) {
            $line = $pricing['lines'][$i] ?? null;
            if ($line === null) {
                continue;
            }
            $cart[$i]['listPrice'] = (float) $item['price'];
            $cart[$i]['price'] = $line['netUnit'];
            $cart[$i]['lineNet'] = $line['netTotal'];
            $cart[$i]['lineDiscount'] = $line['discount'];
            $cart[$i]['saleName'] = $line['saleName'];
        }

        return $cart;
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function discountLabel(array $pricing): string
    {
        return $pricing['code'] !== null ? 'Discount ('.$pricing['code'].')' : 'Sale discount';
    }

    /**
     * Totals + per-line display values, shared by every cart JSON response.
     *
     * @param  list<array<string, mixed>>  $cart
     * @return array<string, mixed>
     */
    private function cartPayload(array $cart): array
    {
        $pricing = $this->pricing($cart);
        $lines = [];
        foreach ($cart as $i => $item) {
            $line = $pricing['lines'][$i] ?? null;
            if ($line !== null) {
                $lines[$item['key']] = [
                    'unit' => $this->formatPrice($line['netUnit']),
                    'total' => $this->formatPrice($line['netTotal']),
                ];
            }
        }

        return [
            'itemCount' => $this->cartTotals($cart)['itemCount'],
            'subtotal' => $this->formatPrice($pricing['subtotal']),
            'discount' => $this->formatPrice($pricing['discount']),
            'hasDiscount' => $pricing['discount'] > 0,
            'discountLabel' => $this->discountLabel($pricing),
            'total' => $this->formatPrice($pricing['total']),
            'code' => $pricing['code'],
            'promoError' => $pricing['promoError'],
            'lines' => $lines,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCart(): array
    {
        $cart = session('cart', []);

        return is_array($cart) ? $cart : [];
    }

    /**
     * Drop lines whose product has since been deleted, so they never show as K 0.00.
     *
     * @param  list<array<string, mixed>>  $cart
     * @return list<array<string, mixed>>
     */
    private function pruneUnavailable(array $cart): array
    {
        if ($cart === []) {
            return $cart;
        }
        $ids = array_values(array_unique(array_map(fn (array $i): string => (string) ($i['productId'] ?? ''), $cart)));
        $existing = \App\Models\Product::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $kept = array_values(array_filter($cart, fn (array $i): bool => in_array((string) ($i['productId'] ?? ''), $existing, true)));
        if (count($kept) !== count($cart)) {
            $this->saveCart($kept);
        }

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $cart
     */
    private function saveCart(array $cart): void
    {
        session(['cart' => array_values($cart)]);
    }

    /**
     * @param  list<array<string, mixed>>  $cart
     * @return array{itemCount: int, subtotal: float}
     */
    private function cartTotals(array $cart): array
    {
        $itemCount = 0;
        $subtotal = 0.0;
        foreach ($cart as $item) {
            $qty = (int) ($item['qty'] ?? 0);
            $price = (float) ($item['price'] ?? 0.0);
            $itemCount += $qty;
            $subtotal += $price * $qty;
        }

        return ['itemCount' => $itemCount, 'subtotal' => $subtotal];
    }

    private function cartItemCount(): int
    {
        return $this->cartTotals($this->loadCart())['itemCount'];
    }

    private function formatPrice(float $amount): string
    {
        return 'K '.number_format($amount, 2);
    }

    private function cartErrorResponse(Request $request, int $status, string $message, string $redirectTo): JsonResponse|RedirectResponse
    {
        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['ok' => false, 'message' => $message], $status);
        }

        return redirect($redirectTo);
    }
}
