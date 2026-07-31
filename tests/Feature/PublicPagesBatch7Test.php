<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesBatch7Test extends TestCase
{
    public function test_shop_page_renders_with_layout_and_filters(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('page-no-nav', false);
        $response->assertDontSee('class="lfs-nav"', false);
        $response->assertSee('class="shop-header"', false);
        $response->assertSee('id="shop-filter-form"', false);
    }

    public function test_empty_cart_page_renders(): void
    {
        $response = $this->get('/shop/cart');

        $response->assertOk();
        $response->assertSee('Your cart is empty', false);
    }

    public function test_checkout_redirects_when_cart_empty(): void
    {
        $response = $this->get('/shop/checkout');

        $response->assertRedirect('/shop/cart');
    }

    public function test_checkout_renders_when_cart_has_items(): void
    {
        $this->withSession([
            'cart' => [[
                'key' => 'prod-1::M',
                'productId' => 'prod-1',
                'slug' => 'test-product',
                'name' => 'Test Product',
                'price' => 100.0,
                'image' => '/images/products/placeholder.webp',
                'size' => 'M',
                'qty' => 1,
            ]],
        ]);

        $response = $this->get('/shop/checkout');

        $response->assertOk();
        $response->assertSee('data-place-order', false);
        $response->assertSee('name="paymentMethod"', false);
    }

    public function test_product_not_found_returns_404(): void
    {
        $response = $this->get('/shop/product/nonexistent-slug-xyz');

        $response->assertNotFound();
    }

    public function test_shop_blade_views_render_with_minimal_data(): void
    {
        $shopHtml = view('pages.shop', [
            'title' => 'Shop',
            'description' => 'Test',
            'bodyClass' => 'page-no-hero',
            'products' => [],
            'total' => 0,
            'currentPage' => 1,
            'pages' => 1,
            'cartCount' => 0,
            'filters' => ['sort' => 'latest', 'category' => '', 'gender' => '', 'size' => '', 'minPrice' => '', 'maxPrice' => ''],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('class="shop-header"', $shopHtml);
        $this->assertStringContainsString('No products found', $shopHtml);

        $cartHtml = view('pages.shop-cart', [
            'title' => 'Cart',
            'description' => 'Test',
            'bodyClass' => 'page-no-hero',
            'cart' => [],
            'itemCount' => 0,
            'cartCount' => 0,
            'subtotal' => 'K 0.00',
            'extraStyles' => '',
        ])->render();

        $this->assertStringContainsString('Your cart is empty', $cartHtml);

        $checkoutHtml = view('pages.checkout', [
            'title' => 'Checkout',
            'description' => 'Test',
            'bodyClass' => 'page-no-hero',
            'cartItems' => [],
            'cartCount' => 1,
            'subtotal' => 'K 100.00',
            'total' => 'K 100.00',
            'csrfToken' => 'test-token',
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('data-place-order', $checkoutHtml);
        $this->assertStringContainsString('Checkout', $checkoutHtml);
    }
}
