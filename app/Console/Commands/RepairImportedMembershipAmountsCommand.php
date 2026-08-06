<?php

namespace App\Console\Commands;

use App\Enums\MembershipHistoryEvent;
use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipHistory;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Services\MembershipService;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * One-off (but re-runnable) repair for the 2026-08 bulk member import.
 *
 * Two problems in the same data:
 *
 * 1. MemberImportService used to cast the spreadsheet's "Net amount" cell with a bare
 *    (float) cast. Rows where that cell was stored as text with a thousands separator
 *    (e.g. "1,000.00") got truncated at the comma, silently importing as amount=1.00.
 *    That parsing bug is already fixed going forward (see MemberImportService::parseAmount());
 *    this command repairs the rows that were already imported before the fix, by re-reading
 *    the original spreadsheet.
 *
 * 2. Imported members' membership duration was derived from the spreadsheet's "Payment Plan"
 *    text column alone, never from the amount actually paid. This command re-derives the
 *    duration from amount paid instead: `amount / plan.price` tells you how many terms of
 *    that plan were paid for (e.g. 1000 paid against a "Quarterly" (250) label = 4 quarters).
 *    The only amount that falls short of a clean multiple in this dataset is the `900`-against-
 *    `Annual` (K1000) rows: K900 is treated as a valid Annual price on its own (no discount
 *    tier is actually configured in the system) and honored as a full payment — not chased for
 *    the K100 difference from today's K1000 list price.
 */
class RepairImportedMembershipAmountsCommand extends Command
{
    protected $signature = 'member-import:repair-amounts
        {--dry-run : Print what would change without writing anything}
        {--spreadsheet= : Path to the source xlsx used to recover amounts corrupted by the comma-parsing bug}';

    protected $description = "Repair imported membership_payments corrupted by the comma-thousands-separator parsing bug, and derive membership duration from amount paid instead of the spreadsheet's Payment Plan label.";

    public function handle(MembershipService $membershipService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $spreadsheetPath = $this->option('spreadsheet') ?: base_path('Full Member List (08-07-2026).xlsx');

        $correctedAmounts = $this->loadCorrectedAmountsFromSpreadsheet($spreadsheetPath);
        if ($correctedAmounts === null) {
            $this->warn("Spreadsheet not found at {$spreadsheetPath} — rows corrupted by the comma-parsing bug (amount = 1.00) will be reported as unresolved instead of auto-repaired.");
            $correctedAmounts = [];
        }

        $plans = MembershipPlan::query()->get()->keyBy('id');

        $payments = MembershipPayment::query()
            ->where('payment_gateway', 'import')
            ->with('membership')
            ->orderBy('id')
            ->get();

        $counts = ['fixed_bug' => 0, 'multi_term' => 0, 'nine_hundred_annual' => 0, 'unchanged' => 0, 'expired' => 0];
        $unresolved = [];
        $rowsForDisplay = [];

        foreach ($payments as $payment) {
            $membership = $payment->membership;
            if (! $membership) {
                $unresolved[] = "payment #{$payment->id}: no membership row";

                continue;
            }

            $plan = $plans->get($payment->plan_id);
            if (! $plan || (float) $plan->price <= 0) {
                $unresolved[] = "{$membership->membership_number}: unknown or zero-price plan (plan_id={$payment->plan_id})";

                continue;
            }

            // Strip the "LFS" prefix (added by the membership-number backfill,
            // see member-import:backfill-lfs-prefix) so this still matches
            // the spreadsheet's raw "Ref" cells regardless of whether that
            // backfill has run yet.
            $ref = preg_replace('/^LFS-?/i', '', (string) $membership->membership_number);
            $currentAmount = (float) $payment->amount;
            $bugFixed = false;
            // Read from amount_paid rather than amount: amount_paid is the one field this
            // command never rewrites to anything other than what the member actually paid,
            // so it stays a stable signal of the original imported figure across reruns
            // (amount itself gets normalized to the plan's price/duration below).
            $sourceAmount = (float) $payment->amount_paid;

            if (abs($sourceAmount - 1.0) < 0.001) {
                if (! array_key_exists($ref, $correctedAmounts)) {
                    $unresolved[] = "{$ref}: amount=1.00 (comma-parse bug) but not found in spreadsheet — needs manual fix";

                    continue;
                }
                $sourceAmount = $correctedAmounts[$ref];
                $bugFixed = true;
            }

            $price = (float) $plan->price;
            $multiplier = (int) round($sourceAmount / $price);
            $isCleanMultiple = $multiplier >= 1 && abs(($multiplier * $price) - $sourceAmount) < 0.01;

            $startDate = $membership->start_date instanceof Carbon
                ? $membership->start_date->copy()
                : Carbon::parse($membership->start_date);
            $isNineHundredAnnual = abs($sourceAmount - 900.0) < 0.01 && (int) $plan->duration_months === 12;

            if ($isCleanMultiple) {
                $newAmount = $sourceAmount;
                $newAmountPaid = $sourceAmount;
                $newStatus = MembershipPaymentStatus::Paid;
                $newDurationMonths = $multiplier * (int) $plan->duration_months;
                $kind = $bugFixed ? 'fixed_bug' : ($multiplier > 1 ? 'multi_term' : 'unchanged');
            } elseif ($isNineHundredAnnual) {
                // No discount tier is actually configured in the system — K900 against the
                // K1000 Annual plan is simply what these members paid. Honor it as paid in
                // full instead of treating the K100 difference from today's list price as a
                // shortfall still owed (see EnsureBalancePaid).
                $newAmount = $sourceAmount;
                $newAmountPaid = $sourceAmount;
                $newStatus = MembershipPaymentStatus::Paid;
                $newDurationMonths = (int) $plan->duration_months;
                $kind = 'nine_hundred_annual';
            } else {
                $unresolved[] = "{$ref}: amount={$sourceAmount} doesn't cleanly divide {$plan->name}'s price ({$price}) — needs manual review";

                continue;
            }

            $dates = $membershipService->computePeriodDates($startDate, $newDurationMonths);

            $amountChanged = abs($newAmount - $currentAmount) > 0.001 || abs($newAmountPaid - (float) $payment->amount_paid) > 0.001 || $newStatus !== $payment->status;
            $expiryChanged = $dates['expiryDate'] !== $membership->expiry_date?->toDateString();

            if (! $amountChanged && ! $expiryChanged) {
                $counts['unchanged']++;

                continue;
            }

            $counts[$kind]++;

            $willExpire = $membership->status === MembershipStatus::Active
                && Carbon::parse($dates['expiryDate'])->endOfDay()->isPast();

            $rowsForDisplay[] = [
                'ref' => $ref,
                'plan' => $plan->name,
                'amount' => "{$currentAmount} -> {$newAmount}",
                'amount_paid' => (float) $payment->amount_paid.' -> '.$newAmountPaid,
                'status' => "{$payment->status} -> {$newStatus}",
                'duration' => "? -> {$newDurationMonths}mo",
                'expiry' => ($membership->expiry_date?->toDateString() ?? '—')." -> {$dates['expiryDate']}",
                'will_expire' => $willExpire ? 'YES' : '',
            ];

            if ($willExpire) {
                $counts['expired']++;
            }

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($payment, $membership, $newAmount, $newAmountPaid, $newStatus, $dates, $willExpire, $membershipService, $currentAmount, $newDurationMonths, $ref) {
                $payment->forceFill([
                    'amount' => $newAmount,
                    'amount_paid' => $newAmountPaid,
                    'status' => $newStatus,
                    'covers_from' => $dates['startDate'],
                    'covers_to' => $dates['expiryDate'],
                ])->save();

                $membership->forceFill([
                    'expiry_date' => $dates['expiryDate'],
                    'renewal_due_date' => $dates['renewalDueDate'],
                ])->save();

                MembershipHistory::query()->create([
                    'membership_id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'event' => MembershipHistoryEvent::ManualAdjustment,
                    'from_status' => $membership->status,
                    'to_status' => $membership->status,
                    'plan_id' => $membership->current_plan_id,
                    'metadata' => [
                        'reason' => 'member-import:repair-amounts',
                        'ref' => $ref,
                        'amountBefore' => $currentAmount,
                        'amountAfter' => $newAmount,
                        'durationMonthsAfter' => $newDurationMonths,
                    ],
                    'actor' => 'system:import-repair',
                    'is_active' => false,
                    'created_at' => now(),
                ]);

                if ($willExpire) {
                    $membershipService->expire($membership->id, 'Recomputed from amount paid during import data repair');
                }
            });
        }

        $this->table(
            ['Ref', 'Plan', 'Amount', 'Amount paid', 'Status', 'Duration', 'Expiry', 'Now expired?'],
            array_map(fn ($r) => array_values($r), $rowsForDisplay)
        );

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '').sprintf(
            'Bug-fixed: %d, multi-term extended: %d, K900 Annual honored in full: %d, unchanged (skipped): %d, transitioned to expired: %d',
            $counts['fixed_bug'],
            $counts['multi_term'],
            $counts['nine_hundred_annual'],
            $counts['unchanged'],
            $counts['expired']
        ));

        if ($unresolved !== []) {
            $this->newLine();
            $this->warn('Unresolved rows (left untouched, need manual review):');
            foreach ($unresolved as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, float>|null Ref => corrected amount, or null if the file is missing.
     */
    private function loadCorrectedAmountsFromSpreadsheet(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('All') ?? $spreadsheet->getActiveSheet();

        $headers = [];
        $byRef = [];

        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = trim((string) $cell->getValue());
            }

            if ($rowIndex === 1) {
                $headers = $cells;

                continue;
            }

            if (($cells[0] ?? '') === '') {
                continue;
            }

            $mapped = [];
            foreach ($headers as $i => $header) {
                $mapped[$header] = $cells[$i] ?? '';
            }

            $ref = trim((string) ($mapped['Ref'] ?? ''));
            if ($ref === '') {
                continue;
            }

            $rawAmount = trim((string) ($mapped['Net amount'] ?? ''));
            $byRef[$ref] = (float) str_replace(',', '', $rawAmount);
        }

        return $byRef;
    }
}
