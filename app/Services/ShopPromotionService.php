<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PromotionDiscountType;
use App\Exceptions\PromoCodeException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopPromotion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shop discounts: promo codes and automatic category sales.
 *
 * priceCart() is the single pricing entry point: the cart, checkout, order
 * creation and offline sales all go through it, so every surface agrees on
 * what is charged. Everything is computed from database prices.
 *
 * Rules:
 *  - Automatic sales (no code) are percentage-only; if several match a
 *    product the largest percentage wins.
 *  - A code and a sale never stack: each line gets whichever is better.
 *  - A discount can never take the order to zero.
 */
class ShopPromotionService
{
    /** @var array<string, mixed>|null request-scoped cache, only set inside batched() */
    private ?array $batch = null;

    /** Percentages above this are never accepted. */
    public const MAX_PERCENTAGE = 99;

    /**
     * @param  list<array{productId: string, qty: int, unitPrice?: float|null, fixedPrice?: bool}>  $lines
     *         unitPrice overrides the product's DB price (callers that already
     *         locked the row pass it); fixedPrice marks a hand-set price that
     *         no sale or code may touch.
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     subtotal: float, discount: float, total: float,
     *     code: ?string, promotionId: ?int, saleNames: list<string>
     * }
     *
     * @throws PromoCodeException when a code is supplied but can't be applied
     */
    public function priceCart(array $lines, ?string $code = null, ?User $user = null): array
    {
        $isMember = $user !== null && $this->isActiveMember($user);
        $products = Product::query()
            ->whereIn('id', array_values(array_unique(array_map(fn (array $l): string => (string) $l['productId'], $lines))))
            ->get(['id', 'price', 'category'])
            ->keyBy('id');
        $sales = $this->activeAutomatic($isMember);

        $priced = [];
        foreach ($lines as $line) {
            $productId = (string) $line['productId'];
            $qty = max(0, (int) $line['qty']);
            $product = $products->get($productId);
            $fixed = (bool) ($line['fixedPrice'] ?? false);
            $listUnit = isset($line['unitPrice']) && $line['unitPrice'] !== null
                ? (float) $line['unitPrice']
                : (float) ($product?->price ?? 0);
            $listTotal = round($listUnit * $qty, 2);
            $category = $product?->category;

            $salePct = 0.0;
            $saleName = null;
            if (! $fixed) {
                foreach ($sales as $sale) {
                    if ($this->coversCategory($sale, $category) && (float) $sale->discount_value > $salePct) {
                        $salePct = (float) $sale->discount_value;
                        $saleName = $sale->name;
                    }
                }
            }

            $priced[] = [
                'productId' => $productId,
                'qty' => $qty,
                'category' => $category,
                'fixed' => $fixed,
                'listUnit' => $listUnit,
                'listTotal' => $listTotal,
                // A sale never takes a line below one ngwee, so a tiny price can't round to free.
                'saleDiscount' => min(max(0.0, $listTotal - 0.01), round($listTotal * $salePct / 100, 2)),
                'saleName' => $saleName,
                'codeDiscount' => 0.0,
            ];
        }

        $promotion = null;
        $normalizedCode = $code !== null ? strtoupper(trim($code)) : '';
        if ($normalizedCode !== '') {
            $promotion = $this->resolveCode($normalizedCode, $priced, $isMember);
            $this->allocateCodeDiscount($promotion, $priced);

            if (! collect($priced)->contains(fn (array $l): bool => $l['codeDiscount'] > $l['saleDiscount'])) {
                throw new PromoCodeException('This code doesn\'t give a better price than the sale already on these items.');
            }
        }

        $subtotal = 0.0;
        $discount = 0.0;
        $saleNames = [];
        $out = [];
        foreach ($priced as $l) {
            $codeWins = $promotion !== null && $l['codeDiscount'] > $l['saleDiscount'];
            $lineDiscount = min($l['listTotal'], max($l['saleDiscount'], $l['codeDiscount']));
            $subtotal += $l['listTotal'];
            $discount += $lineDiscount;
            if (! $codeWins && $l['saleDiscount'] > 0 && $l['saleName'] !== null) {
                $saleNames[$l['saleName']] = true;
            }
            $net = round($l['listTotal'] - $lineDiscount, 2);
            $out[] = [
                'productId' => $l['productId'],
                'qty' => $l['qty'],
                'listUnit' => $l['listUnit'],
                'listTotal' => $l['listTotal'],
                'discount' => round($lineDiscount, 2),
                'netTotal' => $net,
                'netUnit' => $l['qty'] > 0 ? round($net / $l['qty'], 2) : $l['listUnit'],
                'viaCode' => $codeWins,
                'saleName' => $codeWins ? null : ($l['saleDiscount'] > 0 ? $l['saleName'] : null),
            ];
        }

        $subtotal = round($subtotal, 2);
        $discount = round($discount, 2);
        $total = round($subtotal - $discount, 2);

        if ($promotion !== null && $total < 0.01) {
            throw new PromoCodeException('This discount can\'t cover the whole order.');
        }

        return [
            'lines' => $out,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'code' => $promotion?->code,
            'promotionId' => $promotion?->id !== null ? (int) $promotion->id : null,
            'saleNames' => array_keys($saleNames),
        ];
    }

    /**
     * The automatic sale price for one product, for display on shop pages.
     *
     * @return array{price: float, percent: float, name: string}|null
     */
    public function saleFor(string $category, float $listPrice, ?User $user = null): ?array
    {
        $isMember = $user !== null && $this->isActiveMember($user);
        $best = null;
        foreach ($this->activeAutomatic($isMember) as $sale) {
            if ($this->coversCategory($sale, $category) && ($best === null || (float) $sale->discount_value > (float) $best->discount_value)) {
                $best = $sale;
            }
        }
        if ($best === null) {
            return null;
        }

        $percent = (float) $best->discount_value;

        return [
            'price' => round($listPrice * (1 - $percent / 100), 2),
            'percent' => $percent,
            'name' => $best->name,
        ];
    }

    /** Whether any promo code is currently usable (decides if the cart shows a code box). */
    public function hasActiveCodes(): bool
    {
        return $this->activeQuery()->whereNotNull('code')->exists();
    }

    public function isActiveMember(User $user): bool
    {
        if ($this->batch !== null && isset($this->batch['member'][$user->id])) {
            return $this->batch['member'][$user->id];
        }
        $active = $user->memberships()->get()->contains(fn ($m): bool => $m->isCardActive());
        if ($this->batch !== null) {
            $this->batch['member'][$user->id] = $active;
        }

        return $active;
    }

    /**
     * Run $callback with active automatic sales (and member checks) loaded once,
     * so list pages don't query per product. The cache never outlives the call.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function batched(callable $callback): mixed
    {
        if ($this->batch !== null) {
            return $callback();
        }
        $this->batch = [];
        try {
            return $callback();
        } finally {
            $this->batch = null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $priced
     */
    private function resolveCode(string $code, array $priced, bool $isMember): ShopPromotion
    {
        $invalid = new PromoCodeException('That promo code isn\'t valid.');
        $promotion = ShopPromotion::query()->where('code', $code)->first();
        if (! $promotion || ! $promotion->is_active) {
            throw $invalid;
        }

        $today = Carbon::today();
        if ($today->lt($promotion->starts_at)) {
            throw $invalid;
        }
        if ($today->gt($promotion->ends_at)) {
            throw $invalid;
        }
        if ($promotion->members_only && ! $isMember) {
            throw new PromoCodeException('This code is for active LFS members. Log in with your member account to use it.');
        }

        $eligible = array_filter($priced, fn (array $l): bool => ! $l['fixed'] && $this->coversCategory($promotion, $l['category']));
        if ($eligible === []) {
            throw $invalid;
        }

        $min = $promotion->min_order_amount !== null ? (float) $promotion->min_order_amount : null;
        if ($min !== null) {
            $cartTotal = array_sum(array_column(array_filter($priced, fn (array $l): bool => ! $l['fixed']), 'listTotal'));
            if ($cartTotal < $min) {
                throw new PromoCodeException('Spend at least K '.number_format($min, 2).' to use this code.');
            }
        }

        return $promotion;
    }

    /**
     * @param  list<array<string, mixed>>  $priced
     */
    private function allocateCodeDiscount(ShopPromotion $promotion, array &$priced): void
    {
        $eligibleIdx = [];
        foreach ($priced as $i => $l) {
            if (! $l['fixed'] && $this->coversCategory($promotion, $l['category'])) {
                $eligibleIdx[] = $i;
            }
        }

        $value = (float) $promotion->discount_value;

        if ($promotion->discount_type === PromotionDiscountType::Percentage) {
            $pct = min($value, self::MAX_PERCENTAGE);
            foreach ($eligibleIdx as $i) {
                $priced[$i]['codeDiscount'] = min($priced[$i]['listTotal'], round($priced[$i]['listTotal'] * $pct / 100, 2));
            }

            return;
        }

        // Fixed Kwacha amount: spread across eligible lines in proportion to their
        // value. A line whose sale already beats its share keeps the sale and drops
        // out, and the amount is re-spread over the rest, so the customer still gets
        // the full stated amount off the lines the code actually applies to.
        $active = $eligibleIdx;
        do {
            $activeTotal = array_sum(array_map(fn (int $i): float => $priced[$i]['listTotal'], $active));
            if ($activeTotal <= 0) {
                return;
            }
            $dropped = array_values(array_filter($active, fn (int $i): bool => $priced[$i]['saleDiscount'] >= $value * $priced[$i]['listTotal'] / $activeTotal));
            $active = array_values(array_diff($active, $dropped));
        } while ($dropped !== [] && $active !== []);

        if ($active === []) {
            return;
        }
        $activeTotal = array_sum(array_map(fn (int $i): float => $priced[$i]['listTotal'], $active));
        if ($value >= $activeTotal) {
            throw new PromoCodeException('This discount can\'t cover the whole order.');
        }

        $remaining = $value;
        $last = end($active);
        foreach ($active as $i) {
            $share = $i === $last
                ? $remaining
                : round($value * $priced[$i]['listTotal'] / $activeTotal, 2);
            $share = min($share, $priced[$i]['listTotal'], $remaining);
            $priced[$i]['codeDiscount'] = round(max(0.0, $share), 2);
            $remaining = round($remaining - $share, 2);
        }
    }

    private function coversCategory(ShopPromotion $promotion, ?string $category): bool
    {
        return $promotion->category === null || $promotion->category === '' || $promotion->category === $category;
    }

    /**
     * @return Collection<int, ShopPromotion>
     */
    private function activeAutomatic(bool $isMember): Collection
    {
        $key = $isMember ? 'sales-member' : 'sales-guest';
        if ($this->batch !== null && isset($this->batch[$key])) {
            return $this->batch[$key];
        }

        $sales = $this->activeQuery()
            ->whereNull('code')
            ->when(! $isMember, fn (Builder $q) => $q->where('members_only', false))
            ->get();
        if ($this->batch !== null) {
            $this->batch[$key] = $sales;
        }

        return $sales;
    }

    /**
     * @return Builder<ShopPromotion>
     */
    private function activeQuery(): Builder
    {
        $today = Carbon::today()->toDateString();

        return ShopPromotion::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $today)
            ->where('ends_at', '>=', $today);
    }

    // ── Admin CRUD ───────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function getAll(): array
    {
        $usage = $this->usageByPromotion();

        return ShopPromotion::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ShopPromotion $p) => $this->toArray($p, $usage[$p->id] ?? null))
            ->all();
    }

    public function findById(int $id): ?array
    {
        $promotion = ShopPromotion::query()->find($id);

        return $promotion ? $this->toArray($promotion, $this->usageByPromotion()[$id] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): array
    {
        $promotion = ShopPromotion::query()->create($this->mapData($data) + [
            'created_by' => $data['createdBy'] ?? null,
        ]);

        return $this->toArray($promotion->fresh());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ?array
    {
        $promotion = ShopPromotion::query()->find($id);
        if (! $promotion) {
            return null;
        }
        $promotion->update($this->mapData($data));

        return $this->toArray($promotion->fresh());
    }

    public function delete(int $id): bool
    {
        return ShopPromotion::query()->whereKey($id)->delete() > 0;
    }

    /**
     * @return array<int, array{orders: int, discounted: float}>
     */
    private function usageByPromotion(): array
    {
        return Order::query()
            ->whereNotNull('shop_promotion_id')
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::PaymentFailed])
            ->selectRaw('shop_promotion_id, COUNT(*) as orders, SUM(discount_amount) as discounted')
            ->groupBy('shop_promotion_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->shop_promotion_id => ['orders' => (int) $row->orders, 'discounted' => (float) $row->discounted],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapData(array $data): array
    {
        $code = isset($data['code']) && trim((string) $data['code']) !== '' ? strtoupper(trim((string) $data['code'])) : null;

        return [
            'name' => $data['name'],
            'code' => $code,
            // Automatic sales are always percentage.
            'discount_type' => $code === null ? PromotionDiscountType::Percentage : ($data['discountType'] ?? PromotionDiscountType::Percentage),
            'discount_value' => $data['discountValue'],
            'category' => ($data['category'] ?? '') !== '' ? $data['category'] : null,
            'members_only' => (bool) ($data['membersOnly'] ?? false),
            'min_order_amount' => ($data['minOrderAmount'] ?? '') !== '' ? $data['minOrderAmount'] : null,
            'starts_at' => $data['startsAt'],
            'ends_at' => $data['endsAt'],
            'is_active' => (bool) ($data['isActive'] ?? true),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array{orders: int, discounted: float}|null  $usage
     * @return array<string, mixed>
     */
    private function toArray(ShopPromotion $p, ?array $usage = null): array
    {
        $today = Carbon::today();
        $status = match (true) {
            ! $p->is_active => 'disabled',
            $today->lt($p->starts_at) => 'upcoming',
            $today->gt($p->ends_at) => 'expired',
            default => 'active',
        };

        return [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'isAutomatic' => $p->code === null,
            'discountType' => $p->discount_type,
            'discountValue' => (float) $p->discount_value,
            'category' => $p->category,
            'membersOnly' => (bool) $p->members_only,
            'minOrderAmount' => $p->min_order_amount !== null ? (float) $p->min_order_amount : null,
            'startsAt' => $p->starts_at?->toDateString(),
            'endsAt' => $p->ends_at?->toDateString(),
            'isActive' => (bool) $p->is_active,
            'status' => $status,
            'notes' => $p->notes,
            'orders' => $usage['orders'] ?? 0,
            'discounted' => $usage['discounted'] ?? 0.0,
        ];
    }
}
