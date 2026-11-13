<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Product;
use App\Services\DashboardStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class OfflineSaleAdminTest extends TestCase
{
    use ActsAsAdmin, RefreshDatabase;

    private function makeProduct(int $stockM = 25, int $stockL = 5, float $price = 100): Product
    {
        return Product::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Jersey',
            'slug' => 'test-jersey-'.Str::random(8),
            'price' => $price,
            'category' => 'jerseys',
            'gender' => 'unisex',
            'sizes' => [['size' => 'M', 'stock' => $stockM], ['size' => 'L', 'stock' => $stockL]],
            'total_stock' => $stockM + $stockL,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function salePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Walk In',
            'customer_phone' => '0971234567',
            'customer_email' => '',
            'payment_method' => 'cash',
            'notes' => '',
            'items' => [['product_id' => $product->id, 'size' => 'M', 'qty' => 2, 'unit_price' => '']],
        ], $overrides);
    }

    public function test_sale_form_lists_products_with_real_stock(): void
    {
        $this->makeProduct();

        $this->actingAsAdmin()->get('/admin/orders/sales/create')
            ->assertOk()
            ->assertSee('Record offline sale')
            ->assertSee('Test Jersey');
    }

    public function test_recording_a_sale_creates_an_offline_collected_order(): void
    {
        $product = $this->makeProduct();
        $admin = $this->makeAdminUser();

        $response = $this->actingAsAdmin($admin)->post('/admin/orders/sales', $this->salePayload($product, [
            'payment_method' => 'mobile_money',
            'customer_email' => 'Walk@Example.com',
        ]));

        $order = Order::query()->firstOrFail();
        $response->assertRedirect('/admin/orders/'.$order->id.'/receipt');

        $this->assertSame(OrderChannel::Offline, $order->channel);
        $this->assertSame(OrderStatus::Collected, $order->status);
        $this->assertSame('mobile_money', $order->payment_method);
        $this->assertSame($admin->id, $order->recorded_by);
        $this->assertNull($order->user_id);
        $this->assertSame('Walk In', $order->customer_name);
        $this->assertSame('0971234567', $order->customer_phone);
        $this->assertSame('walk@example.com', $order->customer_email);
        $this->assertStringStartsWith('LFS-S-', $order->order_number);
        $this->assertSame('200.00', (string) $order->total);

        $item = $order->items()->firstOrFail();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('M', $item->size);
        $this->assertSame(2, $item->qty);
        $this->assertSame('100.00', (string) $item->unit_price);
    }

    public function test_offline_sale_can_take_the_stock_the_website_keeps_in_reserve(): void
    {
        // 25 in stock, threshold 20: the website could only sell 5, but a
        // walk-in can take 10, leaving 15.
        $product = $this->makeProduct(stockM: 25);

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [['product_id' => $product->id, 'size' => 'M', 'qty' => 10, 'unit_price' => '']],
        ]))->assertRedirect();

        $product->refresh();
        $this->assertSame(15, $product->sizes[0]['stock']);
        $this->assertSame(20, $product->total_stock);
    }

    public function test_unit_price_can_be_overridden_and_multiple_lines_are_totalled(): void
    {
        $product = $this->makeProduct(stockM: 30, stockL: 30);

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [
                ['product_id' => $product->id, 'size' => 'M', 'qty' => 1, 'unit_price' => '80'],
                ['product_id' => $product->id, 'size' => 'L', 'qty' => 2, 'unit_price' => ''],
            ],
        ]))->assertRedirect();

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame('280.00', (string) $order->total); // 80 + 2 x 100
        $this->assertCount(2, $order->items);
    }

    public function test_sale_is_refused_when_it_exceeds_the_real_stock(): void
    {
        $product = $this->makeProduct(stockM: 25, stockL: 5);

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [['product_id' => $product->id, 'size' => 'L', 'qty' => 6, 'unit_price' => '']],
        ]))->assertSessionHasErrors('items');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(5, $product->fresh()->sizes[1]['stock']);
    }

    public function test_a_failed_line_rolls_back_the_whole_sale(): void
    {
        $product = $this->makeProduct(stockM: 25, stockL: 1);

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [
                ['product_id' => $product->id, 'size' => 'M', 'qty' => 3, 'unit_price' => ''],
                ['product_id' => $product->id, 'size' => 'L', 'qty' => 2, 'unit_price' => ''],
            ],
        ]))->assertSessionHasErrors('items');

        $product->refresh();
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(25, $product->sizes[0]['stock']); // first line's stock was given back
    }

    public function test_customer_name_phone_and_payment_method_are_required(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['customer_name' => '']))
            ->assertSessionHasErrors('customer_name');
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['customer_phone' => '']))
            ->assertSessionHasErrors('customer_phone');
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['customer_phone' => 'abc']))
            ->assertSessionHasErrors('customer_phone');
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['payment_method' => 'bitcoin']))
            ->assertSessionHasErrors('payment_method');
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_unknown_product_is_rejected(): void
    {
        $product = $this->makeProduct();

        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, [
            'items' => [['product_id' => (string) Str::uuid(), 'size' => 'M', 'qty' => 1, 'unit_price' => '']],
        ]))->assertSessionHasErrors('items.0.product_id');
    }

    public function test_receipt_shows_the_sale(): void
    {
        $product = $this->makeProduct();
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product));
        $order = Order::query()->firstOrFail();

        $this->actingAsAdmin()->get('/admin/orders/'.$order->id.'/receipt')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Walk In')
            ->assertSee('Cash')
            ->assertSee('ZMW 200.00');
    }

    public function test_receipt_is_only_for_offline_sales(): void
    {
        $online = Order::query()->create([
            'order_number' => 'LFS-ONLINE-1', 'customer_name' => 'Jane', 'customer_email' => 'j@example.com',
            'subtotal' => 10, 'total' => 10, 'status' => OrderStatus::Paid,
        ]);

        $this->assertSame(OrderChannel::Online, $online->fresh()->channel);
        $this->actingAsAdmin()->get('/admin/orders/'.$online->id.'/receipt')->assertNotFound();
    }

    public function test_orders_list_can_be_filtered_by_channel(): void
    {
        $product = $this->makeProduct();
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product));
        $offline = Order::query()->firstOrFail();
        $online = Order::query()->create([
            'order_number' => 'LFS-ONLINE-2', 'customer_name' => 'Online Jane', 'customer_email' => 'j@example.com',
            'subtotal' => 10, 'total' => 10, 'status' => OrderStatus::Paid,
        ]);

        $this->actingAsAdmin()->get('/admin/orders?channel=offline')
            ->assertOk()->assertSee($offline->order_number)->assertDontSee($online->order_number);
        $this->actingAsAdmin()->get('/admin/orders?channel=online')
            ->assertOk()->assertSee($online->order_number)->assertDontSee($offline->order_number);
        $this->actingAsAdmin()->get('/admin/orders')
            ->assertOk()->assertSee($offline->order_number)->assertSee($online->order_number)
            ->assertSee('Record offline sale');
    }

    public function test_order_detail_shows_channel_and_payment_method(): void
    {
        $product = $this->makeProduct();
        $this->actingAsAdmin()->post('/admin/orders/sales', $this->salePayload($product, ['payment_method' => 'card']));
        $order = Order::query()->firstOrFail();

        $this->actingAsAdmin()->get('/admin/orders/'.$order->id)
            ->assertOk()
            ->assertSee('Offline (in person)')
            ->assertSee('Card / POS');
    }

    public function test_read_only_auditor_cannot_record_a_sale(): void
    {
        $product = $this->makeProduct();
        $auditor = $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);

        $this->actingAsAdmin($auditor)->post('/admin/orders/sales', $this->salePayload($product))
            ->assertForbidden();

        $this->assertSame(0, Order::query()->count());
        $this->assertInstanceOf(AdminUser::class, $auditor);
    }

    private function recordSale(Product $product, array $overrides = [], ?AdminUser $admin = null): Order
    {
        $this->actingAsAdmin($admin)->post('/admin/orders/sales', $this->salePayload($product, $overrides))->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }

    // ── Void ────────────────────────────────────────────────────────────────

    public function test_voiding_a_sale_cancels_it_restores_stock_and_records_who_and_why(): void
    {
        $product = $this->makeProduct(stockM: 25);
        $order = $this->recordSale($product, [
            'items' => [['product_id' => $product->id, 'size' => 'M', 'qty' => 10, 'unit_price' => '']],
        ]);
        $this->assertSame(15, $product->fresh()->sizes[0]['stock']);

        $admin = $this->makeAdminUser();
        $this->actingAsAdmin($admin)
            ->post('/admin/orders/'.$order->id.'/void', ['void_reason' => 'Wrong size'])
            ->assertRedirect('/admin/orders/'.$order->id);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Wrong size', $order->void_reason);
        $this->assertSame($admin->id, $order->voided_by);
        $this->assertNotNull($order->voided_at);

        $product->refresh();
        $this->assertSame(25, $product->sizes[0]['stock']);
        $this->assertSame(30, $product->total_stock);
    }

    public function test_a_sale_cannot_be_voided_twice(): void
    {
        $product = $this->makeProduct(stockM: 25);
        $order = $this->recordSale($product);

        $this->actingAsAdmin()->post('/admin/orders/'.$order->id.'/void', ['void_reason' => 'Mistake']);
        $this->actingAsAdmin()->post('/admin/orders/'.$order->id.'/void', ['void_reason' => 'Mistake again'])
            ->assertSessionHas('flash.error');

        // Stock went back exactly once: 25 - 2 + 2.
        $this->assertSame(25, $product->fresh()->sizes[0]['stock']);
        $this->assertSame('Mistake', $order->fresh()->void_reason);
    }

    public function test_void_needs_a_reason(): void
    {
        $order = $this->recordSale($this->makeProduct());

        $this->actingAsAdmin()->post('/admin/orders/'.$order->id.'/void', ['void_reason' => ''])
            ->assertSessionHasErrors('void_reason');

        $this->assertSame(OrderStatus::Collected, $order->fresh()->status);
    }

    public function test_online_orders_cannot_be_voided(): void
    {
        $online = Order::query()->create([
            'order_number' => 'LFS-ONLINE-3', 'customer_name' => 'Jane', 'customer_email' => 'j@example.com',
            'subtotal' => 10, 'total' => 10, 'status' => OrderStatus::Collected,
        ]);

        $this->actingAsAdmin()->post('/admin/orders/'.$online->id.'/void', ['void_reason' => 'Nope'])
            ->assertSessionHas('flash.error');

        $this->assertSame(OrderStatus::Collected, $online->fresh()->status);
        $this->assertNull($online->fresh()->voided_at);
    }

    public function test_read_only_auditor_cannot_void(): void
    {
        $order = $this->recordSale($this->makeProduct());
        $auditor = $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);

        $this->actingAsAdmin($auditor)->post('/admin/orders/'.$order->id.'/void', ['void_reason' => 'x'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::Collected, $order->fresh()->status);
    }

    public function test_order_page_offers_void_and_then_shows_the_reason(): void
    {
        $order = $this->recordSale($this->makeProduct());

        $this->actingAsAdmin()->get('/admin/orders/'.$order->id)->assertOk()->assertSee('Void sale')->assertSee('void_reason');

        $this->actingAsAdmin()->post('/admin/orders/'.$order->id.'/void', ['void_reason' => 'Customer changed mind']);

        $this->actingAsAdmin()->get('/admin/orders/'.$order->id)
            ->assertOk()->assertSee('Customer changed mind')->assertDontSee('name="void_reason"', false);
        $this->actingAsAdmin()->get('/admin/orders/'.$order->id.'/receipt')->assertOk()->assertSee('VOIDED');
    }

    // ── Report ──────────────────────────────────────────────────────────────

    public function test_report_totals_by_payment_method_and_leaves_out_voided_sales(): void
    {
        $product = $this->makeProduct(stockM: 100);
        $this->recordSale($product, ['payment_method' => 'cash']);                       // 200
        $this->recordSale($product, ['payment_method' => 'mobile_money']);               // 200
        $voided = $this->recordSale($product, ['payment_method' => 'cash']);             // 200, voided
        $this->actingAsAdmin()->post('/admin/orders/'.$voided->id.'/void', ['void_reason' => 'Mistake']);

        $response = $this->actingAsAdmin()->get('/admin/orders/sales')->assertOk();

        $report = $response->viewData('totals');
        $this->assertSame(2, $report['count']);
        $this->assertSame(400.0, $report['total']);
        $this->assertSame(200.0, $report['byMethod']['cash']['total']);
        $this->assertSame(200.0, $report['byMethod']['mobile_money']['total']);
        $this->assertSame(0.0, $report['byMethod']['card']['total']);
        $this->assertSame(1, $report['voidedCount']);
        $this->assertSame(200.0, $report['voidedTotal']);
        $response->assertSee($voided->order_number)->assertSee('Voided');
    }

    public function test_report_defaults_to_today_in_lusaka_time(): void
    {
        $product = $this->makeProduct(stockM: 100);

        // 23:30 UTC on the 10th is 01:30 on the 11th in Lusaka (UTC+2).
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:30:00', 'UTC'));
        $lateNight = $this->recordSale($product);

        // (Assert on the report's data: the "Sale recorded" flash also contains the number.)
        $ids = fn (string $query): array => $this->actingAsAdmin()->get('/admin/orders/sales'.$query)
            ->assertOk()->viewData('sales')->pluck('id')->all();

        $this->assertSame([], $ids('?from=2026-11-10&to=2026-11-10'));
        $this->assertSame([$lateNight->id], $ids('?from=2026-11-11&to=2026-11-11'));

        // "Today" in Lusaka is the 11th, so no dates shows it too.
        $this->assertSame([$lateNight->id], $ids(''));

        Carbon::setTestNow();
    }

    public function test_report_ignores_bad_dates_and_swaps_a_reversed_range(): void
    {
        $order = $this->recordSale($this->makeProduct());
        $today = Carbon::now('Africa/Lusaka')->toDateString();

        $this->actingAsAdmin()->get('/admin/orders/sales?from=garbage&to=also-garbage')
            ->assertOk()->assertSee($order->order_number);
        $this->actingAsAdmin()->get('/admin/orders/sales?from='.$today.'&to=2020-01-01')->assertOk();
    }

    public function test_report_does_not_include_online_orders(): void
    {
        $online = Order::query()->create([
            'order_number' => 'LFS-ONLINE-4', 'customer_name' => 'Jane', 'customer_email' => 'j@example.com',
            'subtotal' => 10, 'total' => 10, 'status' => OrderStatus::Paid,
        ]);

        $this->actingAsAdmin()->get('/admin/orders/sales')->assertOk()->assertDontSee($online->order_number);
    }

    // ── CSV export ──────────────────────────────────────────────────────────

    public function test_export_downloads_a_csv_with_each_sale(): void
    {
        $product = $this->makeProduct(stockM: 100);
        $admin = $this->makeAdminUser(['name' => 'Till Operator']);
        $sale = $this->recordSale($product, ['payment_method' => 'card', 'customer_name' => 'Walk In'], $admin);
        $voided = $this->recordSale($product);
        $this->actingAsAdmin()->post('/admin/orders/'.$voided->id.'/void', ['void_reason' => 'Mistake']);

        $response = $this->actingAsAdmin()->get('/admin/orders/sales/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('offline-sales-', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Receipt no.', $csv);
        $this->assertStringContainsString($sale->order_number, $csv);
        $this->assertStringContainsString('Card / POS', $csv);
        $this->assertStringContainsString('Test Jersey (M) x2 @ 100.00', $csv);
        $this->assertStringContainsString('Till Operator', $csv);
        $this->assertStringContainsString('Completed', $csv);
        $this->assertStringContainsString('Voided', $csv);
        $this->assertStringContainsString('Mistake', $csv);
    }

    public function test_export_neutralises_values_a_spreadsheet_would_run_as_formulas(): void
    {
        $this->recordSale($this->makeProduct(), ['customer_name' => '=HYPERLINK("http://evil.example","x")']);

        $csv = $this->actingAsAdmin()->get('/admin/orders/sales/export')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_read_only_auditor_can_view_the_report_and_export(): void
    {
        $auditor = $this->makeAdminUser(['role' => AdminRole::ReadOnlyAuditor]);

        $this->actingAsAdmin($auditor)->get('/admin/orders/sales')->assertOk();
        $this->actingAsAdmin($auditor)->get('/admin/orders/sales/export')->assertOk();
    }

    // ── Dashboard ───────────────────────────────────────────────────────────

    public function test_dashboard_counts_this_months_offline_sales_but_not_voided_ones(): void
    {
        $product = $this->makeProduct(stockM: 100);
        $this->recordSale($product);                       // 200
        $voided = $this->recordSale($product);             // 200, voided
        $this->actingAsAdmin()->post('/admin/orders/'.$voided->id.'/void', ['void_reason' => 'Mistake']);

        $old = $this->recordSale($product);                // last month: not counted
        Order::query()->whereKey($old->id)->update(['created_at' => now()->subMonths(2)]);

        $this->assertSame(200.0, app(DashboardStatsService::class)->compute()['offlineRevenue']);

        $this->actingAsAdmin()->get('/admin/dashboard')->assertOk()->assertSee('Offline Sales');
    }
}
