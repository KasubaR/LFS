<?php

namespace App\Services;

use App\Enums\PromotionDiscountType;
use App\Models\MembershipPlan;
use App\Models\Promotion;
use Illuminate\Support\Carbon;

class PromotionService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getAll(): array
    {
        return Promotion::query()
            ->with('plan')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Promotion $promotion) => $this->toPromotion($promotion))
            ->all();
    }

    public function findById(int $id): ?array
    {
        $promotion = Promotion::query()->with('plan')->find($id);

        return $promotion ? $this->toPromotion($promotion) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): array
    {
        $promotion = Promotion::query()->create($this->mapData($data) + [
            'created_by' => $data['createdBy'] ?? null,
        ]);

        return $this->toPromotion($promotion->fresh('plan'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ?array
    {
        $promotion = Promotion::query()->find($id);
        if (! $promotion) {
            return null;
        }

        // created_by is deliberately not touched here — it records who first
        // created the promotion, not who last edited it.
        $promotion->update($this->mapData($data));

        return $this->toPromotion($promotion->fresh('plan'));
    }

    public function delete(int $id): bool
    {
        return Promotion::query()->whereKey($id)->delete() > 0;
    }

    /**
     * The currently-active promotion for a plan, if any: enabled, inside its
     * date window, and either scoped to this plan or applying sitewide (null
     * plan_id). A plan-specific promotion wins over a sitewide one when both
     * are active at once.
     */
    public function findActiveForPlan(int $planId): ?Promotion
    {
        $today = Carbon::today()->toDateString();

        return Promotion::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $today)
            ->where('ends_at', '>=', $today)
            ->where(function ($q) use ($planId) {
                $q->whereNull('plan_id')->orWhere('plan_id', $planId);
            })
            ->orderByRaw('plan_id IS NULL')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * The single integration point for checkout: resolves whatever promotion
     * is currently active for the plan and returns the price to charge along
     * with the promotion/discount to record on the payment row.
     *
     * @param  float|null  $basePrice  Override for the plan's own price — used
     *                                 when the amount actually due isn't the
     *                                 plan's list price (e.g. the late-joiner
     *                                 annual fee reduction), so an active
     *                                 promotion still composes on top of it.
     * @return array{amount: float, promotionId: ?int, discountAmount: float}
     */
    public function priceForPlan(MembershipPlan $plan, ?float $basePrice = null): array
    {
        $price = $basePrice ?? (float) $plan->price;
        $promotion = $this->findActiveForPlan((int) $plan->id);

        if (! $promotion) {
            return ['amount' => $price, 'promotionId' => null, 'discountAmount' => 0.0];
        }

        $value = (float) $promotion->discount_value;
        $discount = $promotion->discount_type === PromotionDiscountType::Percentage
            ? round($price * $value / 100, 2)
            : $value;

        // Clamp so a misconfigured promotion can never push the price below zero.
        $discount = max(0.0, min($discount, $price));

        return [
            'amount' => round($price - $discount, 2),
            'promotionId' => (int) $promotion->id,
            'discountAmount' => $discount,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapData(array $data): array
    {
        return [
            'name' => $data['name'],
            'plan_id' => $data['planId'] ?? null,
            'discount_type' => $data['discountType'] ?? PromotionDiscountType::Percentage,
            'discount_value' => $data['discountValue'],
            'starts_at' => $data['startsAt'],
            'ends_at' => $data['endsAt'],
            'is_active' => $data['isActive'] ?? true,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toPromotion(Promotion $promotion): array
    {
        $today = Carbon::today();
        $status = match (true) {
            ! $promotion->is_active => 'disabled',
            $today->lt($promotion->starts_at) => 'upcoming',
            $today->gt($promotion->ends_at) => 'expired',
            default => 'active',
        };

        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'planId' => $promotion->plan_id,
            'planName' => $promotion->plan?->name,
            'discountType' => $promotion->discount_type,
            'discountValue' => (float) $promotion->discount_value,
            'startsAt' => $promotion->starts_at?->toDateString(),
            'endsAt' => $promotion->ends_at?->toDateString(),
            'isActive' => (bool) $promotion->is_active,
            'status' => $status,
            'notes' => $promotion->notes,
            'createdBy' => $promotion->created_by,
            'createdAt' => (string) $promotion->created_at,
            'updatedAt' => (string) $promotion->updated_at,
        ];
    }
}
