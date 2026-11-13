<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OfflinePaymentMethod;
use App\Enums\OrderChannel;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Enums\OrderStatus;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Product;
use App\Services\AdminPermissionService;
use App\Services\OrderService;
use App\Services\ShopSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * In-person (offline) sales recorded by an admin. The sale is saved as a
 * normal order with channel = offline, so it shows in Orders and in reports.
 */
class OfflineSaleController extends Controller
{
    /** Sales days are Lusaka days, whatever the server clock says. */
    private const TIMEZONE = 'Africa/Lusaka';

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

    public function void(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:255'],
        ], [
            'void_reason.required' => 'Say why the sale is being voided.',
        ]);

        try {
            $this->orderService->voidOfflineSale($id, (int) $this->permissions->currentAdmin($request)?->id, trim($data['void_reason']));
        } catch (\DomainException $e) {
            return redirect('/admin/orders/'.$id)->with('flash', ['error' => $e->getMessage()]);
        }

        return redirect('/admin/orders/'.$id)->with('flash', ['success' => 'Sale voided and the stock put back.']);
    }

    /**
     * Day-by-day report of offline sales, with totals per payment method for
     * end-of-day reconciliation.
     */
    public function report(Request $request): View
    {
        [$from, $to] = $this->dateRange($request);
        $sales = $this->orderService->offlineSalesBetween($from->copy()->utc(), $to->copy()->utc());

        $kept = $sales->where('status', '!=', OrderStatus::Cancelled);
        $voided = $sales->where('status', OrderStatus::Cancelled);

        $byMethod = [];
        foreach (OfflinePaymentMethod::LABELS as $method => $label) {
            $rows = $kept->where('payment_method', $method);
            $byMethod[$method] = ['label' => $label, 'count' => $rows->count(), 'total' => (float) $rows->sum('total')];
        }

        return view('admin.orders.sales-report', [
            'pageTitle' => 'Offline sales report',
            'activePage' => 'orders',
            'sales' => $sales,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'count' => $kept->count(),
                'total' => (float) $kept->sum('total'),
                'voidedCount' => $voided->count(),
                'voidedTotal' => (float) $voided->sum('total'),
                'byMethod' => $byMethod,
            ],
            'paymentLabels' => OfflinePaymentMethod::LABELS,
            'timezone' => self::TIMEZONE,
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin/dashboard'],
                ['label' => 'Orders', 'url' => '/admin/orders'],
                ['label' => 'Offline sales report'],
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);
        $sales = $this->orderService->offlineSalesBetween($from->copy()->utc(), $to->copy()->utc());

        $adminNames = AdminUser::query()
            ->whereIn('id', $sales->pluck('recorded_by')->merge($sales->pluck('voided_by'))->filter()->unique())
            ->pluck('name', 'id');

        $filename = 'offline-sales-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($sales, $adminNames): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Receipt no.', 'Date (Lusaka)', 'Customer', 'Phone', 'Email', 'Payment method',
                'Items', 'Total (ZMW)', 'Status', 'Recorded by', 'Voided at (Lusaka)', 'Void reason', 'Voided by',
            ]);

            foreach ($sales as $order) {
                $items = $order->items
                    ->map(fn ($i): string => $i->name.' ('.$i->size.') x'.$i->qty.' @ '.number_format((float) $i->unit_price, 2, '.', ''))
                    ->implode('; ');

                fputcsv($out, array_map([$this, 'csvSafe'], [
                    $order->order_number,
                    $order->created_at->copy()->timezone(self::TIMEZONE)->format('Y-m-d H:i'),
                    $order->customer_name,
                    $order->customer_phone,
                    $order->customer_email,
                    OfflinePaymentMethod::LABELS[$order->payment_method] ?? (string) $order->payment_method,
                    $items,
                    number_format((float) $order->total, 2, '.', ''),
                    $order->status === OrderStatus::Cancelled ? 'Voided' : 'Completed',
                    (string) ($adminNames[$order->recorded_by] ?? ''),
                    $order->voided_at?->copy()->timezone(self::TIMEZONE)->format('Y-m-d H:i') ?? '',
                    (string) $order->void_reason,
                    (string) ($adminNames[$order->voided_by] ?? ''),
                ]));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Start and end of the requested Lusaka days (default: today).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(Request $request): array
    {
        $parse = function (mixed $value): ?Carbon {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }

            try {
                return Carbon::createFromFormat('Y-m-d', $value, self::TIMEZONE)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        };

        $today = Carbon::now(self::TIMEZONE)->startOfDay();
        $from = $parse($request->query('from')) ?? $today->copy();
        $to = $parse($request->query('to')) ?? $from->copy();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        // Keep a report to a year at most.
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        return [$from, $to->copy()->endOfDay()];
    }

    /** Stops a spreadsheet treating a typed value as a formula. */
    private function csvSafe(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
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
