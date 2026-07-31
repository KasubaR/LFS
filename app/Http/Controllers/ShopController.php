<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Requests\AddToCartRequest;
use App\Services\ProductService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ShopController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly ProductService $productService,
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
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/shop.css').'">',
            'extraScripts' => '<script src="'.asset('js/shop.js').'"></script>',
        ]);
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

        return view('pages.productDetails', [
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
        $cart = $this->loadCart();
        ['itemCount' => $itemCount, 'subtotal' => $subtotal] = $this->cartTotals($cart);

        return view('pages.shop-cart', [
            'title' => 'Your Cart — LFS Shop',
            'description' => 'Review your LFS merchandise cart.',
            'bodyClass' => 'page-no-hero',
            'cart' => $cart,
            'itemCount' => $itemCount,
            'cartCount' => $itemCount,
            'subtotal' => $this->formatPrice($subtotal),
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/cart.css').'">',
        ]);
    }

    public function checkout(): View|RedirectResponse
    {
        $cart = $this->loadCart();
        ['itemCount' => $itemCount, 'subtotal' => $subtotalAmount] = $this->cartTotals($cart);

        if ($itemCount === 0) {
            return redirect('/shop/cart');
        }

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
                'subtotal' => $price * $qty,
                'image' => $image,
            ];
        }, $cart);

        return view('pages.checkout', [
            'title' => 'Checkout — LFS Shop',
            'description' => 'Complete your order for official LFS regalia and gear.',
            'bodyClass' => 'page-no-hero',
            'cartItems' => $cartItems,
            'cartCount' => $itemCount,
            'subtotal' => $this->formatPrice($subtotalAmount),
            'total' => $this->formatPrice($subtotalAmount),
            'csrfToken' => csrf_token(),
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/checkout.css').'">',
            'extraScripts' => '<script src="'.asset('js/checkout.js').'"></script>'
                .'<script src="'.asset('js/checkout-lenco.js').'"></script>',
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
                'price' => (float) $product['price'],
                'image' => $product['thumbnail'] ?: '/images/products/placeholder.webp',
                'size' => $size,
                'qty' => min($qty, $maxQty),
            ];
        }

        $this->saveCart($cart);
        ['itemCount' => $itemCount, 'subtotal' => $subtotal] = $this->cartTotals($cart);

        if ($this->wantsJson($request)) {
            return $this->jsonResponse([
                'ok' => true,
                'message' => 'Item added to cart.',
                'itemCount' => $itemCount,
                'subtotal' => $this->formatPrice($subtotal),
            ]);
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
                    $cart[$i]['qty'] = $newQty;
                }
                break;
            }
        }

        $this->saveCart($cart);

        if ($this->wantsJson($request)) {
            ['itemCount' => $itemCount, 'subtotal' => $subtotal] = $this->cartTotals($cart);

            return $this->jsonResponse([
                'ok' => true,
                'itemCount' => $itemCount,
                'subtotal' => $this->formatPrice($subtotal),
            ]);
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
            ['itemCount' => $itemCount, 'subtotal' => $subtotal] = $this->cartTotals($cart);

            return $this->jsonResponse([
                'ok' => true,
                'itemCount' => $itemCount,
                'subtotal' => $this->formatPrice($subtotal),
            ]);
        }

        return redirect('/shop/cart');
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
