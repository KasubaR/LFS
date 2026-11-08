<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\EventTicketOrder;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Services\LencoService;
use App\Services\MembershipPaymentService;
use App\Services\MembershipService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Tickets\TicketConfirmationService;
use App\Services\Tickets\TicketPaymentOutcomeService;
use App\Support\PaymentAmount;
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

    // Lenco answers "not found" for a charge that never reached it (a request that
    // failed before sending, or the server dying between saving our reference and
    // calling Lenco). After this long it will not appear any more, so release it.
    private const NEVER_SENT_AFTER_MINUTES = 30;

    public function handle(
        LencoService $lenco,
        PaymentService $payments,
        OrderService $orders,
        MembershipPaymentService $membershipPayments,
        MembershipService $membershipService,
        TicketPaymentOutcomeService $ticketOutcome,
        TicketConfirmationService $ticketConfirmations,
    ): int {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);

        [$orderChecked, $orderResolved] = $this->pollOrderPayments($lenco, $payments, $orders, $cutoff);
        [$membershipChecked, $membershipResolved] = $this->pollMembershipPayments($lenco, $membershipPayments, $membershipService, $cutoff);
        [$ticketChecked, $ticketResolved] = $this->pollTicketPayments($lenco, $ticketOutcome, $cutoff);

        // Paid ticket orders whose confirmation email failed to send (mail outage).
        $ticketConfirmations->retryUnsent();

        $this->info(sprintf(
            'Shop: checked %d, resolved %d. Membership: checked %d, resolved %d. Tickets: checked %d, resolved %d.',
            $orderChecked,
            $orderResolved,
            $membershipChecked,
            $membershipResolved,
            $ticketChecked,
            $ticketResolved,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pollOrderPayments(LencoService $lenco, PaymentService $payments, OrderService $orders, Carbon $cutoff): array
    {
        // Event ticket payments are settled by pollTicketPayments(); the shop
        // path below would mark a shop order that doesn't exist and restore
        // stock that was never taken.
        $stuck = Payment::query()
            ->where('status', 'pending')
            ->whereNotNull('transaction_id')
            ->where('order_number', 'not like', config('tickets.order_prefix', 'LFSEV-').'%')
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
                $neverSent = ($result['found'] ?? true) === false
                    && $payment->created_at->lt(now()->subMinutes(self::NEVER_SENT_AFTER_MINUTES));

                if (! $neverSent && ! $this->isExpired($payment)) {
                    continue;
                }
                // Lenco is still saying "pending" but this payment blew past its own
                // expiry window a while ago — the customer isn't coming back to it.
                // Stop reserving stock against it.
                $internalStatus = 'cancelled';
            }

            $extra = ['lencoStatus' => $result['status']];
            $mismatch = null;
            if ($internalStatus === 'completed') {
                $extra['completedAt'] = now()->toDateTimeString();
                $mismatch = PaymentAmount::mismatch((float) $payment->amount, $result, (string) ($payment->currency ?: 'ZMW'));
                if ($mismatch !== null) {
                    Log::critical('[PollPendingPaymentsCommand] Paid amount does not match the order; holding for review', [
                        'orderNumber' => $payment->order_number,
                        'paymentId' => $payment->id,
                        'expected' => $mismatch['expected'],
                        'received' => $mismatch['received'],
                        'currency' => $mismatch['currency'],
                    ]);
                    $extra['failureReason'] = sprintf('amount_mismatch: expected %.2f ZMW, received %.2f %s', $mismatch['expected'], $mismatch['received'], $mismatch['currency']);
                }
            } elseif ($internalStatus === 'failed') {
                $extra['failureReason'] = $result['failureReason'] ?? null;
                $extra['failedAt'] = now()->toDateTimeString();
            }

            if (! $payments->updateStatus($payment->id, $internalStatus, $extra)) {
                continue;
            }

            $newOrderStatus = match ($internalStatus) {
                'completed' => $mismatch !== null ? OrderStatus::PaymentReview : 'paid',
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

    /**
     * Settles event ticket payments through the same single path as the
     * webhook and verify endpoint, and releases reservations that lapsed.
     *
     * @return array{0: int, 1: int}
     */
    private function pollTicketPayments(LencoService $lenco, TicketPaymentOutcomeService $outcome, Carbon $cutoff): array
    {
        $prefix = (string) config('tickets.order_prefix', 'LFSEV-');

        $stuck = Payment::query()
            ->where('status', 'pending')
            ->whereNotNull('transaction_id')
            ->where('order_number', 'like', $prefix.'%')
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
                Log::warning('[PollPendingPaymentsCommand] ticket verify failed', [
                    'paymentId' => $payment->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $result = $result + ['internalStatus' => 'pending'];

            if (in_array($result['internalStatus'], ['completed', 'failed', 'cancelled'], true)) {
                if ($outcome->apply($payment, $result, 'poller') !== TicketPaymentOutcomeService::NOOP) {
                    $resolved++;
                }

                continue;
            }

            // Still pending at the gateway: release the reservation once it lapses.
            $order = EventTicketOrder::query()->where('order_number', $payment->order_number)->first();
            if ($order === null) {
                continue;
            }

            if ($outcome->expireIfStale($order)) {
                $resolved++;
            }

            // Give up on a payment the gateway never resolves, so it isn't polled forever.
            // (The order stays cancelled. Money arriving after this point is logged as critical.)
            $giveUpAfter = $order->expires_at?->copy()->addHours((int) config('tickets.poll_give_up_hours', 24));
            if ($giveUpAfter !== null && now()->greaterThan($giveUpAfter)) {
                $outcome->giveUpOnPayment($payment);
            }
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
        // 'partially_paid' is included too — a stuck top-up attempt (the
        // payment's own webhook missed) is the same "recover from a missed
        // webhook" scenario this command exists for, just on a payment that
        // already has some money against it.
        $stuck = MembershipPayment::query()
            ->whereIn('status', ['pending', 'partially_paid'])
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

                if (($result['found'] ?? true) === false
                    && $payment->updated_at->lt(now()->subMinutes(self::NEVER_SENT_AFTER_MINUTES))) {
                    // Lenco has no record of this reference: the charge never went out.
                    // Free the member to start a fresh attempt instead of leaving them
                    // stuck on "already in progress".
                    $membershipPayments->recordGatewayInitiation($payment->id, [
                        'paymentReference' => null,
                        'pendingChargeAmount' => null,
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

            $paymentArray = $membershipPayments->findById((int) $payment->id);
            if ($paymentArray !== null && $membershipPayments->holdsForAmountMismatch($paymentArray, $result)) {
                continue;
            }
            $resolvedCharge = $paymentArray !== null ? $membershipPayments->resolveConfirmedCharge($paymentArray) : null;

            if ($resolvedCharge === null) {
                // Nothing pending to credit — already processed by a webhook/
                // verify() call that beat this poller to it. Not an error.
                continue;
            }

            try {
                $membershipService->handlePaymentUpdate((int) $payment->id, $resolvedCharge['amountPaid'], array_merge($resolvedCharge['extra'], [
                    'paidAt' => now(),
                    'lencoStatus' => $result['status'],
                    'lencoResponse' => $result['rawResponse'] ?? [],
                ]));
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
