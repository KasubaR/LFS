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
 *    Amounts that fall short of a clean multiple (only the `900`-against-`Annual` rows in this
 *    dataset) are treated as an underpayment: the plan's full duration is kept, but the payment
 *    is marked partially_paid with the shortfall recorded as still owed.
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

        $counts = ['fixed_bug' => 0, 'multi_term' => 0, 'partial' => 0, 'unchanged' => 0, 'expired' => 0];
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

            $ref = $membership->membership_number;
            $currentAmount = (float) $payment->amount;
            $bugFixed = false;
            $sourceAmount = $currentAmount;

            if (abs($currentAmount - 1.0) < 0.001) {
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

            if ($isCleanMultiple) {
                $newAmount = $sourceAmount;
                $newAmountPaid = $sourceAmount;
                $newStatus = MembershipPaymentStatus::Paid;
                $newDurationMonths = $multiplier * (int) $plan->duration_months;
                $kind = $bugFixed ? 'fixed_bug' : ($multiplier > 1 ? 'multi_term' : 'unchanged');
            } elseif (abs($sourceAmount - 900.0) < 0.01 && (int) $plan->duration_months === 12) {
                // The one known underpayment pattern in this dataset: paid 900 against a
                // 1000-priced Annual plan. Keep the full Annual duration but record the
                // shortfall so the member is prompted to pay it (see EnsureBalancePaid).
                $newAmount = $price;
                $newAmountPaid = $sourceAmount;
                $newStatus = MembershipPaymentStatus::PartiallyPaid;
                $newDurationMonths = (int) $plan->duration_months;
                $kind = 'partial';
            } else {
                $unresolved[] = "{$ref}: amount={$sourceAmount} doesn't cleanly divide {$plan->name}'s price ({$price}) — needs manual review";

                continue;
            }

            $startDate = $membership->start_date instanceof Carbon
                ? $membership->start_date->copy()
                : Carbon::parse($membership->start_date);
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
            'Bug-fixed: %d, multi-term extended: %d, marked partially paid: %d, unchanged (skipped): %d, transitioned to expired: %d',
            $counts['fixed_bug'],
            $counts['multi_term'],
            $counts['partial'],
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
