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

            if (! in_array($result['internalStatus'], ['completed', 'failed'], true)) {
                continue;
            }

            $extra = ['lencoStatus' => $result['status']];
            if ($result['internalStatus'] === 'completed') {
                $extra['completedAt'] = now()->toDateTimeString();
            } else {
                $extra['failureReason'] = $result['failureReason'] ?? null;
                $extra['failedAt'] = now()->toDateTimeString();
            }

            if (! $payments->updateStatus($payment->id, $result['internalStatus'], $extra)) {
                continue;
            }

            $orders->updateStatus(
                $payment->order_number,
                $result['internalStatus'] === 'completed' ? 'paid' : 'payment_failed',
                byOrderNumber: true,
            );
            $resolved++;
        }

        return [$checked, $resolved];
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
