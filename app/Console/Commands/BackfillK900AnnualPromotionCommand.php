<?php

namespace App\Console\Commands;

use App\Enums\PromotionDiscountType;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\Promotion;
use Illuminate\Console\Command;

/**
 * Retroactively labels the already-corrected K900 Annual import payments (see
 * RepairImportedMembershipAmountsCommand) with the promotion they actually
 * represent, so they show up as a proper 10% discount instead of a bare
 * "amount happens to be 900" payment. Idempotent — a rerun only touches rows
 * that don't already have a promotion attached.
 *
 * This does not touch amount / amount_paid / status: those are already
 * correct from the earlier repair. It only sets promotion_id and
 * discount_amount.
 */
class BackfillK900AnnualPromotionCommand extends Command
{
    protected $signature = 'promotions:backfill-k900-annual
        {--dry-run : Print what would change without writing anything}';

    protected $description = "Tag the 2026-08 bulk import's already-corrected K900 Annual payments with a historical 10% Early Bird Annual promotion.";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $plan = MembershipPlan::query()->where('billing_cycle', 'annual')->first();
        if (! $plan) {
            $this->error('No Annual membership plan found — nothing to backfill.');

            return self::FAILURE;
        }

        $payments = MembershipPayment::query()
            ->where('payment_gateway', 'import')
            ->where('plan_id', $plan->id)
            ->where('amount', 900)
            ->where('amount_paid', 900)
            ->where('status', 'paid')
            ->whereNull('promotion_id')
            ->with('membership')
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty()) {
            $this->info('No untagged K900 Annual import payments found — nothing to do.');

            return self::SUCCESS;
        }

        $startDates = $payments
            ->map(fn (MembershipPayment $p) => $p->membership?->start_date)
            ->filter()
            ->sort();

        $promotionName = 'Early Bird Annual — 2026 Import';
        $existingPromotion = Promotion::query()->where('name', $promotionName)->first();

        if ($dryRun) {
            // Never create the promotion row on a dry run — only report what would happen.
            $promotionLabel = $existingPromotion ? "#{$existingPromotion->id} ({$promotionName})" : "a new '{$promotionName}' promotion";
        } else {
            $promotion = $existingPromotion ?? Promotion::query()->create([
                'name' => $promotionName,
                'plan_id' => $plan->id,
                'discount_type' => PromotionDiscountType::Percentage,
                'discount_value' => 10.00,
                'starts_at' => $startDates->first()?->toDateString() ?? now()->toDateString(),
                'ends_at' => $startDates->last()?->toDateString() ?? now()->toDateString(),
                // Closed historical record — never meant to auto-apply again.
                'is_active' => false,
                'notes' => "Backfilled for the 2026-08 bulk import's K900 Annual payments.",
            ]);
            $promotionLabel = "#{$promotion->id} ({$promotion->name})";
        }

        $rows = [];
        foreach ($payments as $payment) {
            $ref = preg_replace('/^LFS-?/i', '', (string) $payment->membership?->membership_number);
            $rows[] = [$ref, (string) $payment->id, 'K100 (10%)'];

            if (! $dryRun) {
                $payment->forceFill([
                    'promotion_id' => $promotion->id,
                    'discount_amount' => 100.00,
                ])->save();
            }
        }

        $this->table(['Ref', 'Payment ID', 'Discount tagged'], $rows);

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '').sprintf(
            'Tagged %d K900 Annual payment(s) with promotion %s.',
            count($rows),
            $promotionLabel
        ));

        return self::SUCCESS;
    }
}
