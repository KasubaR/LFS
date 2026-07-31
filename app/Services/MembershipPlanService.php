<?php

namespace App\Services;

use App\Models\MembershipPlan;

class MembershipPlanService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getActivePlans(): array
    {
        return MembershipPlan::query()
            ->where('is_active', true)
            ->orderBy('duration_months')
            ->get()
            ->map(fn (MembershipPlan $plan) => $this->toPlan($plan))
            ->all();
    }

    public function findById(int $id): ?array
    {
        $plan = MembershipPlan::query()->find($id);

        return $plan ? $this->toPlan($plan) : null;
    }

    public function findByBillingCycle(string $billingCycle): ?array
    {
        $plan = MembershipPlan::query()
            ->where('billing_cycle', $billingCycle)
            ->where('is_active', true)
            ->first();

        return $plan ? $this->toPlan($plan) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPlan(MembershipPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'billingCycle' => $plan->billing_cycle,
            'price' => (float) $plan->price,
            'durationMonths' => (int) $plan->duration_months,
            'isActive' => (bool) $plan->is_active,
        ];
    }
}
