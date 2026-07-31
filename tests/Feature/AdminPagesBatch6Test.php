<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminPagesBatch6Test extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_products_list_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/products');

        $response->assertOk();
        $response->assertSee('class="admin-sidebar"', false);
    }

    public function test_orders_list_renders(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/orders');

        $response->assertOk();
    }

    public function test_orders_show_blade_renders_with_minimal_data(): void
    {
        $html = view('admin.orders.show', [
            'pageTitle' => 'Order',
            'activePage' => 'orders',
            'order' => [
                'id' => 1,
                'order_number' => 'LFS-001',
                'customer_name' => 'Test User',
                'customer_email' => 'test@example.com',
                'customer_phone' => '0977000000',
                'status' => 'paid',
                'subtotal' => 100.0,
                'total' => 100.0,
                'items' => [],
                'created_at' => '2026-01-01 12:00:00',
                'updated_at' => '2026-01-01 12:00:00',
            ],
            'formatPrice' => fn ($v) => 'K '.number_format((float) $v, 2),
            'payment' => null,
            'adminUser' => ['name' => 'Admin', 'email' => 'admin@test.com'],
            'counts' => ['pendingMembers' => 0, 'pendingOrders' => 0, 'pendingGallery' => 0, 'newMessages' => 0],
            'breadcrumbs' => [],
            'extraStyles' => '',
            'extraScripts' => '',
        ])->render();

        $this->assertStringContainsString('LFS-001', $html);
    }
}
