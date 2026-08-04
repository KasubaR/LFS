<?php

namespace App\Console\Commands;

use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Services\LencoService;
use App\Services\MembershipPaymentService;
use App\Services\MembershipService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class PollPendingPaymentsCommand extends Command
{
    protected $signature = 'payments:poll-pending';

    protected $description = 'Re-verify shop and membership payments stuck pending, recovering from missed webhooks';

    private const STALE_AFTER_MINUTES = 5;

    private const BATCH_LIMIT = 50;

    // If Lenco still reports a non-terminal status but the payment's own expiry has
    // long passed, stop waiting on it — treat it as cancelled and release the stock.
    private const EXPIRY_GRACE_MINUTES = 15;

    public function handle(
        LencoService $lenco,
        PaymentService $payments,
        OrderService $orders,
        MembershipPaymentService $membershipPayments,
        MembershipService $membershipService,
    ): int {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);

        [$orderChecked, $orderResolved] = $this->pollOrderPayments($lenco, $payments, $orders, $cutoff);
        [$membershipChecked, $membershipResolved] = $this->pollMembershipPayments($lenco, $membershipPayments, $membershipService, $cutoff);

        $this->info(sprintf(
            'Shop: checked %d, resolved %d. Membership: checked %d, resolved %d.',
            $orderChecked,
            $orderResolved,
            $membershipChecked,
            $membershipResolved,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pollOrderPayments(LencoService $lenco, PaymentService $payments, OrderService $orders, Carbon $cutoff): array
    {
        $stuck = Payment::query()
            ->where('status', 'pending')
            ->whereNotNull('transaction_id')
            ->where('created_at', '<', $cutoff)
            ->limit(self::BATCH_LIMIT)
            ->get();

        $checked = 0;
        $resolved = 0;

        foreach ($stuck as $payment) {
            $checked++;

            try {
                $result = $lenco->verifyPayment($payment->transaction_id, true);
            } catch (Throwable $e) {
                Log::warning('[PollPendingPaymentsCommand] order verify failed', [
                    'paymentId' => $payment->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $internalStatus = $result['internalStatus'];

            if (! in_array($internalStatus, ['completed', 'failed', 'cancelled'], true)) {
                if (! $this->isExpired($payment)) {
                    continue;
                }
                // Lenco is still saying "pending" but this payment blew past its own
                // expiry window a while ago — the customer isn't coming back to it.
                // Stop reserving stock against it.
                $internalStatus = 'cancelled';
            }

            $extra = ['lencoStatus' => $result['status']];
            if ($internalStatus === 'completed') {
                $extra['completedAt'] = now()->toDateTimeString();
            } elseif ($internalStatus === 'failed') {
                $extra['failureReason'] = $result['failureReason'] ?? null;
                $extra['failedAt'] = now()->toDateTimeString();
            }

            if (! $payments->updateStatus($payment->id, $internalStatus, $extra)) {
                continue;
            }

            $newOrderStatus = match ($internalStatus) {
                'completed' => 'paid',
                'cancelled' => 'cancelled',
                default => 'payment_failed',
            };
            $orders->updateStatus($payment->order_number, $newOrderStatus, byOrderNumber: true);

            if (in_array($internalStatus, ['failed', 'cancelled'], true)) {
                $orders->restoreStockForOrder($payment->order_number);
            }

            $resolved++;
        }

        return [$checked, $resolved];
    }

    private function isExpired(Payment $payment): bool
    {
        if ($payment->expires_at === null) {
            return false;
        }

        return now()->greaterThan($payment->expires_at->copy()->addMinutes(self::EXPIRY_GRACE_MINUTES));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pollMembershipPayments(
        LencoService $lenco,
        MembershipPaymentService $membershipPayments,
        MembershipService $membershipService,
        Carbon $cutoff,
    ): array {
        $stuck = MembershipPayment::query()
            ->where('status', 'pending')
            ->whereNotNull('payment_reference')
            ->where('created_at', '<', $cutoff)
            ->limit(self::BATCH_LIMIT)
            ->get();

        $checked = 0;
        $resolved = 0;

        foreach ($stuck as $payment) {
            $checked++;

            try {
                $result = $lenco->verifyPayment($payment->payment_reference, true);
            } catch (Throwable $e) {
                Log::warning('[PollPendingPaymentsCommand] membership verify failed', [
                    'paymentId' => $payment->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($result['internalStatus'] !== 'completed') {
                if (in_array($result['internalStatus'], ['failed', 'cancelled'], true)) {
                    $membershipPayments->markFailed($payment->id, [
                        'lencoStatus' => $result['status'],
                        'lencoResponse' => $result['rawResponse'] ?? [],
                    ]);
                    $resolved++;

                    continue;
                }

                if ($result['internalStatus'] !== $payment->lenco_status) {
                    $membershipPayments->recordGatewayInitiation($payment->id, [
                        'lencoStatus' => $result['status'],
                        'lencoResponse' => $result['rawResponse'] ?? [],
                    ]);
                }

                continue;
            }

            try {
                $membershipService->handlePaymentUpdate((int) $payment->id, (float) $payment->amount, [
                    'paidAt' => now(),
                    'lencoStatus' => $result['status'],
                    'lencoResponse' => $result['rawResponse'] ?? [],
                ]);
                $resolved++;
            } catch (Throwable $e) {
                Log::warning('[PollPendingPaymentsCommand] membership activation failed', [
                    'paymentId' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$checked, $resolved];
    }
}
