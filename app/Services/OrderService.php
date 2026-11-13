<?php

namespace App\Services;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly ShopPromotionService $promotions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: int, subtotal: float, discount: float, total: float}
     *
     * @throws \App\Exceptions\InsufficientStockException if any line item can't be fulfilled;
     *         the whole transaction (order + stock) rolls back in that case.
     * @throws \App\Exceptions\PromoCodeException if a promo code was supplied but can't be applied;
     *         likewise rolls everything back.
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            // Reserve stock first — if any line fails, the transaction rolls back
            // before the order/order_items rows are ever written. decrementStockForOrder()
            // also returns the product's current DB price, which we use below instead of
            // trusting the client-submitted cart price (defends against a stale/tampered cart).
            $lines = [];
            $subtotal = 0.0;
            foreach ($data['items'] as $item) {
                $qty = (int) ($item['qty'] ?? 1);
                $unitPrice = $this->productService->decrementStockForOrder(
                    (string) ($item['productId'] ?? ''),
                    (string) ($item['size'] ?? ''),
                    $qty,
                );
                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;

                $lines[] = [
                    'productId' => $item['productId'] ?? '',
                    'name' => $item['name'] ?? '',
                    'size' => $item['size'] ?? '',
                    'qty' => $qty,
                    'unitPrice' => $unitPrice,
                    'lineTotal' => $lineTotal,
                ];
            }

            $priced = $this->promotions->priceCart(
                array_map(fn (array $l): array => [
                    'productId' => (string) $l['productId'],
                    'qty' => $l['qty'],
                    'unitPrice' => $l['unitPrice'],
                ], $lines),
                $data['promoCode'] ?? null,
                isset($data['userId']) ? User::query()->find($data['userId']) : null,
            );
            $subtotal = $priced['subtotal'];

            $order = Order::query()->create([
                'user_id' => $data['userId'] ?? null,
                'order_number' => $data['orderNumber'],
                'customer_name' => $data['customerName'],
                'customer_email' => strtolower($data['customerEmail']),
                'customer_phone' => $data['customerPhone'] ?? '',
                'notes' => $data['notes'] ?? '',
                'subtotal' => $subtotal,
                'total' => $priced['total'],
                'discount_amount' => $priced['discount'],
                'shop_promotion_id' => $priced['promotionId'],
                'promo_code' => $priced['code'],
                'status' => $data['status'] ?? 'pending_payment',
            ]);

            foreach ($lines as $i => $line) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $line['productId'],
                    'name' => $line['name'],
                    'size' => $line['size'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unitPrice'],
                    'line_total' => $line['lineTotal'],
                    'discount_amount' => $priced['lines'][$i]['discount'],
                ]);
            }

            return [
                'id' => (int) $order->id,
                'subtotal' => $subtotal,
                'discount' => $priced['discount'],
                'total' => $priced['total'],
            ];
        });
    }

    /**
     * Record an in-person (offline) sale made by an admin. The goods are handed
     * over and paid for on the spot, so the order is created as Collected.
     * Offline sales may take the units the website keeps in reserve, but never
     * more than is actually in stock.
     *
     * Each item: productId, size, qty and an optional unitPrice that overrides
     * the product's current price (e.g. a discount agreed at the stall).
     *
     * @param  array<string, mixed>  $data  customerName, customerPhone, customerEmail?, notes?,
     *                                      paymentMethod, items
     * @return array{id: int, orderNumber: string, total: float}
     *
     * @throws \App\Exceptions\InsufficientStockException if any line can't be fulfilled;
     *         the whole transaction (sale + stock) rolls back in that case.
     */
    public function createOfflineSale(array $data, int $adminId): array
    {
        return DB::transaction(function () use ($data, $adminId): array {
            $lines = [];
            $total = 0.0;

            foreach ($data['items'] as $item) {
                $qty = (int) ($item['qty'] ?? 1);
                $dbPrice = $this->productService->decrementStockForOrder(
                    (string) ($item['productId'] ?? ''),
                    (string) ($item['size'] ?? ''),
                    $qty,
                    respectThreshold: false,
                );
                $hasOverride = isset($item['unitPrice']) && $item['unitPrice'] !== '';
                $unitPrice = $hasOverride ? (float) $item['unitPrice'] : $dbPrice;
                $lineTotal = round($unitPrice * $qty, 2);
                $total += $lineTotal;

                $lines[] = [
                    'productId' => (string) $item['productId'],
                    'name' => (string) ($item['name'] ?? ''),
                    'size' => (string) $item['size'],
                    'qty' => $qty,
                    'unitPrice' => $unitPrice,
                    'lineTotal' => $lineTotal,
                    'fixedPrice' => $hasOverride,
                ];
            }

            // A hand-set unit price is final: no sale or code touches that line.
            $priced = $this->promotions->priceCart(
                array_map(fn (array $l): array => [
                    'productId' => $l['productId'],
                    'qty' => $l['qty'],
                    'unitPrice' => $l['unitPrice'],
                    'fixedPrice' => $l['fixedPrice'],
                ], $lines),
                $data['promoCode'] ?? null,
                $data['member'] ?? null,
            );
            $total = $priced['subtotal'];

            $order = Order::query()->create([
                'user_id' => null,
                'order_number' => $this->generateOfflineOrderNumber(),
                'customer_name' => $data['customerName'],
                'customer_email' => strtolower((string) ($data['customerEmail'] ?? '')),
                'customer_phone' => $data['customerPhone'],
                'notes' => $data['notes'] ?? '',
                'subtotal' => $total,
                'total' => $priced['total'],
                'discount_amount' => $priced['discount'],
                'shop_promotion_id' => $priced['promotionId'],
                'promo_code' => $priced['code'],
                'status' => OrderStatus::Collected,
                'channel' => OrderChannel::Offline,
                'payment_method' => $data['paymentMethod'],
                'recorded_by' => $adminId,
            ]);

            foreach ($lines as $i => $line) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $line['productId'],
                    'name' => $line['name'],
                    'size' => $line['size'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unitPrice'],
                    'line_total' => $line['lineTotal'],
                    'discount_amount' => $priced['lines'][$i]['discount'],
                ]);
            }

            return ['id' => (int) $order->id, 'orderNumber' => $order->order_number, 'total' => $priced['total']];
        });
    }

    /**
     * Undo an offline sale (recorded by mistake, or the goods came back): the
     * order moves to Cancelled, the stock goes back to the shelf, and who/when/why
     * is kept on the order. Only a Collected offline sale can be voided.
     *
     * @throws \DomainException if the order isn't an offline sale that can still be voided
     */
    public function voidOfflineSale(int $orderId, int $adminId, string $reason): void
    {
        DB::transaction(function () use ($orderId, $adminId, $reason): void {
            $order = Order::query()->lockForUpdate()->find($orderId);

            if ($order === null || $order->channel !== OrderChannel::Offline) {
                throw new \DomainException('Only offline sales can be voided.');
            }

            if ($order->status !== OrderStatus::Collected) {
                throw new \DomainException('This sale has already been voided.');
            }

            $this->restoreStockForOrder($order->order_number);

            $order->update([
                'status' => OrderStatus::Cancelled,
                'voided_at' => now(),
                'voided_by' => $adminId,
                'void_reason' => $reason,
            ]);
        });
    }

    /**
     * Offline sales created between two moments (inclusive), newest first.
     * Voided sales are included so a report can show them separately.
     *
     * @return Collection<int, Order>
     */
    public function offlineSalesBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Order::query()
            ->with('items')
            ->where('channel', OrderChannel::Offline)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Money taken by offline sales since a moment, excluding voided sales.
     */
    public function offlineRevenueSince(CarbonInterface $since): float
    {
        return (float) Order::query()
            ->where('channel', OrderChannel::Offline)
            ->where('status', OrderStatus::Collected)
            ->where('created_at', '>=', $since)
            ->sum('total');
    }

    private function generateOfflineOrderNumber(): string
    {
        do {
            $number = 'LFS-S-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        } while (Order::query()->where('order_number', $number)->exists());

        return $number;
    }

    /**
     * Give back the stock reserved for an order — used when a payment ends up
     * failed/cancelled after stock was already decremented at order-creation time.
     */
    public function restoreStockForOrder(string $orderNumber): void
    {
        DB::transaction(function () use ($orderNumber): void {
            $order = Order::query()->with('items')->where('order_number', $orderNumber)->first();
            if ($order === null) {
                return;
            }

            foreach ($order->items as $item) {
                $this->productService->restoreStock(
                    (string) $item->product_id,
                    (string) $item->size,
                    (int) $item->qty,
                );
            }
        });
    }

    public function updateStatus(int|string $identifier, string $status, bool $byOrderNumber = false): void
    {
        $query = Order::query();

        if ($byOrderNumber) {
            $query->where('order_number', $identifier);
        } else {
            $query->whereKey($identifier);
        }

        $query->update([
            'status' => $status,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function getAll(array $options = []): array
    {
        $limit = max(1, (int) ($options['limit'] ?? 25));
        $offset = max(0, (int) ($options['offset'] ?? 0));

        $query = Order::query()->orderByDesc('created_at');

        if (isset($options['status']) && $options['status'] !== '') {
            $query->where('status', $options['status']);
        }

        if (isset($options['channel']) && in_array($options['channel'], OrderChannel::ALL, true)) {
            $query->where('channel', $options['channel']);
        }

        return $query
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (Order $order) => $this->toOrderSummary($order))
            ->all();
    }

    public function countByStatus(?string $status = null, ?string $channel = null): int
    {
        $query = Order::query();

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($channel !== null && in_array($channel, OrderChannel::ALL, true)) {
            $query->where('channel', $channel);
        }

        return $query->count();
    }

    public function findById(int $id): ?array
    {
        $order = Order::query()->with('items')->find($id);

        return $order ? $this->toOrder($order) : null;
    }

    public function findByOrderNumber(string $orderNumber): ?array
    {
        $order = Order::query()
            ->with('items')
            ->where('order_number', $orderNumber)
            ->first();

        return $order ? $this->toOrder($order) : null;
    }

    /**
     * Orders owned by the member (user_id) plus legacy guest checkouts matching their email.
     *
     * @return list<array<string, mixed>>
     */
    public function listForMember(int $userId, string $email, int $limit = 50): array
    {
        $normalizedEmail = strtolower(trim($email));

        return Order::query()
            ->withCount('items')
            ->where(function ($query) use ($userId, $normalizedEmail): void {
                $query->where('user_id', $userId)
                    ->orWhere('customer_email', $normalizedEmail);
            })
            ->orderByDesc('created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(function (Order $order) {
                $summary = $this->toOrderSummary($order);
                $summary['itemCount'] = (int) ($order->items_count ?? 0);

                return $summary;
            })
            ->all();
    }

    public function findForMember(string $orderNumber, int $userId, string $email): ?array
    {
        $normalizedEmail = strtolower(trim($email));

        $order = Order::query()
            ->with('items')
            ->where('order_number', $orderNumber)
            ->where(function ($query) use ($userId, $normalizedEmail): void {
                $query->where('user_id', $userId)
                    ->orWhere('customer_email', $normalizedEmail);
            })
            ->first();

        return $order ? $this->toOrder($order) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toOrderSummary(Order $order): array
    {
        return [
            'id' => $order->id,
            'userId' => $order->user_id,
            'orderNumber' => $order->order_number,
            'customerName' => $order->customer_name,
            'customerEmail' => $order->customer_email,
            'customerPhone' => $order->customer_phone,
            'subtotal' => (float) $order->subtotal,
            'total' => (float) $order->total,
            'discount' => (float) $order->discount_amount,
            'promoCode' => $order->promo_code,
            'status' => $order->status,
            'channel' => $order->channel ?? OrderChannel::Online,
            'paymentMethod' => $order->payment_method,
            'recordedBy' => $order->recorded_by,
            'voidedAt' => $order->voided_at?->toIso8601String(),
            'voidReason' => $order->void_reason,
            'createdAt' => (string) $order->created_at,
            'updatedAt' => (string) $order->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toOrder(Order $order): array
    {
        $summary = $this->toOrderSummary($order);
        $summary['notes'] = $order->notes ?? '';
        $summary['items'] = $order->items->map(fn (OrderItem $item) => [
            'name' => $item->name,
            'size' => $item->size,
            'qty' => (int) $item->qty,
            'unitPrice' => (float) $item->unit_price,
            'lineTotal' => (float) $item->line_total,
            'discount' => (float) $item->discount_amount,
            'productId' => $item->product_id,
        ])->all();

        return $summary;
    }
}
