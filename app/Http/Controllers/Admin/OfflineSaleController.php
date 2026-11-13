<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OfflinePaymentMethod;
use App\Enums\OrderChannel;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\AdminPermissionService;
use App\Services\OrderService;
use App\Services\ShopSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * In-person (offline) sales recorded by an admin. The sale is saved as a
 * normal order with channel = offline, so it shows in Orders and in reports.
 */
class OfflineSaleController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ShopSettingsService $shopSettings,
        private readonly AdminPermissionService $permissions,
    ) {}

    public function create(): View
    {
        return view('admin.orders.sale-form', [
            'pageTitle' => 'Record offline sale',
            'activePage' => 'orders',
            'products' => $this->productOptions(),
            'paymentMethods' => OfflinePaymentMethod::LABELS,
            'onlineSelloutThreshold' => $this->shopSettings->onlineSelloutThreshold(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin/dashboard'],
                ['label' => 'Orders', 'url' => '/admin/orders'],
                ['label' => 'Record sale'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30', 'regex:/\d{6,}/'],
            'customer_email' => ['nullable', 'string', 'email', 'max:254'],
            'payment_method' => ['required', 'string', 'in:'.implode(',', OfflinePaymentMethod::ALL)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'string', 'exists:products,id'],
            'items.*.size' => ['required', 'string', 'max:20'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ], [
            'customer_name.required' => 'Enter the customer’s name.',
            'customer_phone.required' => 'Enter the customer’s phone number.',
            'customer_phone.regex' => 'Please enter a valid phone number.',
            'items.required' => 'Add at least one item.',
            'items.min' => 'Add at least one item.',
            'items.*.product_id.required' => 'Choose a product for every line.',
            'items.*.size.required' => 'Choose a size for every line.',
            'items.*.qty.min' => 'Quantity must be at least 1.',
        ]);

        $names = Product::query()
            ->whereIn('id', array_column($data['items'], 'product_id'))
            ->pluck('name', 'id');

        $items = array_map(fn (array $i): array => [
            'productId' => $i['product_id'],
            'name' => (string) ($names[$i['product_id']] ?? ''),
            'size' => $i['size'],
            'qty' => (int) $i['qty'],
            'unitPrice' => $i['unit_price'] ?? null,
        ], array_values($data['items']));

        try {
            $result = $this->orderService->createOfflineSale([
                'customerName' => $data['customer_name'],
                'customerPhone' => $data['customer_phone'],
                'customerEmail' => $data['customer_email'] ?? '',
                'notes' => $data['notes'] ?? '',
                'paymentMethod' => $data['payment_method'],
                'items' => $items,
            ], (int) $this->permissions->currentAdmin($request)?->id);
        } catch (InsufficientStockException $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('[OfflineSaleController] Failed to record sale: '.$e->getMessage());

            return back()->withInput()->withErrors(['items' => 'Could not record the sale. Please try again.']);
        }

        return redirect('/admin/orders/'.$result['id'].'/receipt')
            ->with('flash', ['success' => 'Sale '.$result['orderNumber'].' recorded.']);
    }

    public function receipt(int $id): View
    {
        $order = Order::query()->with('items')->where('channel', OrderChannel::Offline)->findOrFail($id);

        return view('admin.orders.receipt', [
            'pageTitle' => 'Receipt '.$order->order_number,
            'activePage' => 'orders',
            'order' => $order,
            'paymentLabel' => OfflinePaymentMethod::LABELS[$order->payment_method] ?? (string) $order->payment_method,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin/dashboard'],
                ['label' => 'Orders', 'url' => '/admin/orders'],
                ['label' => $order->order_number, 'url' => '/admin/orders/'.$order->id],
                ['label' => 'Receipt'],
            ],
        ]);
    }

    /**
     * Active products with real per-size stock, for the sale form.
     *
     * @return list<array<string, mixed>>
     */
    private function productOptions(): array
    {
        return Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'sizes' => collect(is_array($p->sizes) ? $p->sizes : [])
                    ->map(fn (array $s): array => ['size' => (string) ($s['size'] ?? ''), 'stock' => (int) ($s['stock'] ?? 0)])
                    ->filter(fn (array $s): bool => $s['size'] !== '')
                    ->values()
                    ->all(),
            ])
            ->all();
    }
}
