<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly ProductService $productService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: int, subtotal: float, total: float}
     *
     * @throws \App\Exceptions\InsufficientStockException if any line item can't be fulfilled;
     *         the whole transaction (order + stock) rolls back in that case.
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

            $order = Order::query()->create([
                'user_id' => $data['userId'] ?? null,
                'order_number' => $data['orderNumber'],
                'customer_name' => $data['customerName'],
                'customer_email' => strtolower($data['customerEmail']),
                'customer_phone' => $data['customerPhone'] ?? '',
                'notes' => $data['notes'] ?? '',
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'status' => $data['status'] ?? 'pending_payment',
            ]);

            foreach ($lines as $line) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $line['productId'],
                    'name' => $line['name'],
                    'size' => $line['size'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unitPrice'],
                    'line_total' => $line['lineTotal'],
                ]);
            }

            return ['id' => (int) $order->id, 'subtotal' => $subtotal, 'total' => $subtotal];
        });
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

        return $query
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (Order $order) => $this->toOrderSummary($order))
            ->all();
    }

    public function countByStatus(?string $status = null): int
    {
        if ($status === null) {
            return Order::query()->count();
        }

        return Order::query()->where('status', $status)->count();
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
            'status' => $order->status,
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
            'productId' => $item->product_id,
        ])->all();

        return $summary;
    }
}
