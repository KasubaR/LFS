<?php

namespace App\Console\Commands;

use App\Models\MembershipPayment;
use App\Services\MembershipPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off (but re-runnable) backfill for payments that were paid before the
 * receipt_number column existed — most notably the 2026-08 bulk member
 * import, whose payment_reference was set to the member's own membership
 * number rather than a real receipt id (see MemberImportService::importRow()).
 *
 * Assigns each still-unnumbered paid/partially-paid payment a fresh
 * sequential receipt number, oldest first, so the numbering reflects the
 * order the payments were actually received in.
 */
class BackfillReceiptNumbersCommand extends Command
{
    protected $signature = 'payments:backfill-receipt-numbers
        {--dry-run : Print what would change without writing anything}';

    protected $description = 'Assign receipt numbers to paid membership_payments rows that predate the receipt_number column.';

    public function handle(MembershipPaymentService $paymentService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $payments = MembershipPayment::query()
            ->where('amount_paid', '>', 0)
            ->whereNull('receipt_number')
            ->orderByRaw('COALESCE(paid_at, created_at) asc')
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty()) {
            $this->info('No payments need a receipt number.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($payments as $payment) {
            if ($dryRun) {
                $rows[] = [$payment->id, $payment->membership_id, (string) ($payment->paid_at ?? $payment->created_at), '(preview only)'];

                continue;
            }

            DB::transaction(function () use ($payment, $paymentService, &$rows) {
                $receiptNumber = $paymentService->generateReceiptNumber();

                $payment->forceFill(['receipt_number' => $receiptNumber])->save();

                $rows[] = [$payment->id, $payment->membership_id, (string) ($payment->paid_at ?? $payment->created_at), $receiptNumber];
            });
        }

        $this->table(['Payment ID', 'Membership ID', 'Paid at', 'Receipt No'], $rows);
        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '').count($rows).' payment(s) '.($dryRun ? 'would be' : 'were').' assigned a receipt number.');

        return self::SUCCESS;
    }
}
