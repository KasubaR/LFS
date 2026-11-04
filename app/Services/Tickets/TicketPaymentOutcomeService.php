<?php

namespace App\Services\Tickets;

use App\Events\EventTicketOrderNeedsReview;
use App\Events\EventTicketOrderPaid;
use App\Models\Event;
use App\Models\EventTicketOrder;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONE place a ticket payment result changes a ticket order.
 *
 * The Lenco webhook, the verify endpoint and the pending-payment poller all
 * call {@see apply()} and nothing else, so all three produce identical state
 * (docs/EVENT_TICKETING_PLAN.md, B1).
 *
 * Safety rules:
 *  - The payment row's atomic terminal-status guard decides which caller "wins";
 *    losers (duplicate webhook, webhook + poller race) get NOOP and do nothing.
 *  - Order transitions are conditional UPDATEs (`WHERE status IN (allowed from)`),
 *    so a stale read can never move an order through an illegal transition.
 *  - Lock order is always event row first, then order row.
 *  - Money is never silently dropped: a success that cannot be honoured
 *    (no capacity left, amount mismatch, event cancelled...) moves the order to
 *    `payment_review_required` for an admin, and issues nothing.
 */
class TicketPaymentOutcomeService
{
    public const PAID = 'paid';

    public const REVIVED = 'revived';

    public const REVIEW = 'review';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const PENDING = 'pending';

    public const NOOP = 'noop';

    public function __construct(
        private readonly PaymentService $payments,
        private readonly TicketAvailability $availability,
        private readonly TicketPricingService $pricing,
    ) {}

    public static function isTicketOrderNumber(string $orderNumber): bool
    {
        return str_starts_with($orderNumber, (string) config('tickets.order_prefix', 'LFSEV-'));
    }

    /**
     * @param  array<string, mixed>  $lencoResult  normalised Lenco result (needs internalStatus; uses status, amount, currency, failureReason)
     * @param  string  $source  webhook | verify | poller (logged / passed to events)
     * @param  array<string, mixed>  $paymentExtra  extra payment columns, e.g. webhook fields
     */
    public function apply(Payment $payment, array $lencoResult, string $source, ?string $completedAt = null, array $paymentExtra = []): string
    {
        $internal = (string) ($lencoResult['internalStatus'] ?? 'pending');
        if (! in_array($internal, ['completed', 'failed', 'cancelled'], true)) {
            return self::PENDING;
        }

        /** @var list<object> $afterCommit */
        $afterCommit = [];

        $outcome = DB::transaction(function () use ($payment, $lencoResult, $internal, $source, $completedAt, $paymentExtra, &$afterCommit): string {
            $order = EventTicketOrder::query()->where('order_number', $payment->order_number)->first();
            if ($order === null) {
                Log::warning('[TicketPaymentOutcome] no ticket order for payment', ['orderNumber' => $payment->order_number]);

                return self::NOOP;
            }

            // Lock order: event row, then order row.
            $event = Event::query()->whereKey($order->event_id)->lockForUpdate()->first();
            $order = EventTicketOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $extra = $paymentExtra + ['lencoStatus' => $lencoResult['status'] ?? null];
            if ($internal === 'completed') {
                $extra['completedAt'] = $completedAt ?? now()->toDateTimeString();
            } elseif ($internal === 'failed') {
                $extra['failureReason'] = $lencoResult['failureReason'] ?? null;
                $extra['failedAt'] = now()->toDateTimeString();
            }

            // Atomic: only one concurrent caller gets `true`.
            if (! $this->payments->updateStatus((int) $payment->id, $internal, $extra)) {
                // Lost the race (duplicate delivery) or the payment was already
                // closed. A success on a payment we had given up on is real
                // money with no order to attach it to: shout about it.
                $current = Payment::query()->find($payment->id);
                if ($internal === 'completed' && $current !== null && in_array($current->status, ['failed', 'cancelled'], true)) {
                    Log::critical('[TicketPaymentOutcome] gateway reports success on a closed payment; refund or reconcile manually', [
                        'orderNumber' => $payment->order_number,
                        'paymentId' => $payment->id,
                        'paymentStatus' => $current->status,
                        'source' => $source,
                    ]);
                }

                return self::NOOP;
            }

            if ($internal !== 'completed') {
                return $this->onUnsuccessful($order, $internal);
            }

            return $this->onSuccess($order, $event, $lencoResult, $source, $afterCommit);
        });

        foreach ($afterCommit as $domainEvent) {
            event($domainEvent);
        }

        return $outcome;
    }

    /**
     * Lenco definitively refused the charge at initiation. Nothing was charged.
     */
    public function applyInitiationFailure(Payment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason): void {
            $this->payments->updateStatus((int) $payment->id, 'failed', [
                'failureReason' => $reason,
                'failedAt' => now()->toDateTimeString(),
            ]);

            $order = EventTicketOrder::query()->where('order_number', $payment->order_number)->first();
            if ($order !== null) {
                $this->transition($order, [EventTicketOrder::STATUS_PENDING_PAYMENT], EventTicketOrder::STATUS_FAILED);
            }
        });
    }

    /**
     * Cancels a pending order whose reservation has lapsed (plus a short slack
     * for in-flight approvals). The PAYMENT is left pending on purpose, so a
     * late "successful" from Lenco can still be recorded and routed through
     * {@see apply()}'s late-payment rule instead of being rejected.
     */
    public function expireIfStale(EventTicketOrder $order): bool
    {
        if ($order->status !== EventTicketOrder::STATUS_PENDING_PAYMENT || $order->expires_at === null) {
            return false;
        }

        $lapse = $order->expires_at->copy()->addMinutes((int) config('tickets.late_slack_minutes', 2));
        if (now()->lessThan($lapse)) {
            return false;
        }

        return $this->transition(
            $order,
            [EventTicketOrder::STATUS_PENDING_PAYMENT],
            EventTicketOrder::STATUS_CANCELLED,
            ['cancelled_at' => now()]
        );
    }

    /**
     * Stops the poller asking Lenco about a payment that never resolved.
     */
    public function giveUpOnPayment(Payment $payment): bool
    {
        return $this->payments->updateStatus((int) $payment->id, 'cancelled', [
            'failureReason' => 'Gave up polling: no final status from the gateway.',
            'failedAt' => now()->toDateTimeString(),
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function onUnsuccessful(EventTicketOrder $order, string $internal): string
    {
        if ($order->status !== EventTicketOrder::STATUS_PENDING_PAYMENT) {
            return self::NOOP;
        }

        if ($internal === 'cancelled') {
            $this->transition($order, [EventTicketOrder::STATUS_PENDING_PAYMENT], EventTicketOrder::STATUS_CANCELLED, ['cancelled_at' => now()]);

            return self::CANCELLED;
        }

        $this->transition($order, [EventTicketOrder::STATUS_PENDING_PAYMENT], EventTicketOrder::STATUS_FAILED);

        return self::FAILED;
    }

    /**
     * @param  array<string, mixed>  $lencoResult
     * @param  list<object>  $afterCommit
     */
    private function onSuccess(EventTicketOrder $order, ?Event $event, array $lencoResult, string $source, array &$afterCommit): string
    {
        $status = $order->status;

        if (in_array($status, [EventTicketOrder::STATUS_PAID, EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED], true)) {
            return self::NOOP;
        }

        if (! $this->amountMatches($order, $lencoResult)) {
            return $this->toReview($order, 'amount_mismatch', $source, $afterCommit);
        }

        if ($status === EventTicketOrder::STATUS_FAILED) {
            return $this->toReview($order, 'payment_after_failure', $source, $afterCommit);
        }

        // Reservation still live: it has been holding the tickets (and the
        // member discount) the whole time, so confirming is always safe.
        if ($status === EventTicketOrder::STATUS_PENDING_PAYMENT
            && $order->expires_at !== null
            && $order->expires_at->isFuture()) {
            if ($this->transition($order, [EventTicketOrder::STATUS_PENDING_PAYMENT], EventTicketOrder::STATUS_PAID, ['paid_at' => now()])) {
                $afterCommit[] = new EventTicketOrderPaid($order->fresh(), $source);

                return self::PAID;
            }

            return self::NOOP;
        }

        // Late: the reservation lapsed (or the order was cancelled). The
        // tickets may have been sold to someone else, so only honour it if
        // everything it needs is still free; otherwise hold it for an admin.
        return $this->reviveOrReview($order, $event, $source, $afterCommit);
    }

    /**
     * @param  list<object>  $afterCommit
     */
    private function reviveOrReview(EventTicketOrder $order, ?Event $event, string $source, array &$afterCommit): string
    {
        $reason = null;

        if ($event === null || ($event->ticket_status ?? 'scheduled') === 'cancelled') {
            $reason = 'event_cancelled';
        } else {
            $remaining = $this->availability->remainingCapacity($event);
            if ($remaining !== null && $remaining < (int) $order->quantity) {
                $reason = 'capacity_unavailable';
            } elseif ($order->is_member_pricing && $order->membership_id !== null
                && $this->pricing->discountAlreadyClaimed($event, (string) $order->membership_id, (string) $order->id)) {
                $reason = 'discount_unavailable';
            }
        }

        if ($reason !== null) {
            return $this->toReview($order, $reason, $source, $afterCommit);
        }

        $moved = $this->transition(
            $order,
            [EventTicketOrder::STATUS_PENDING_PAYMENT, EventTicketOrder::STATUS_CANCELLED],
            EventTicketOrder::STATUS_PAID,
            ['paid_at' => now(), 'cancelled_at' => null, 'review_reason' => null]
        );

        if (! $moved) {
            return self::NOOP;
        }

        $afterCommit[] = new EventTicketOrderPaid($order->fresh(), $source, revived: true);

        return self::REVIVED;
    }

    /**
     * @param  list<object>  $afterCommit
     */
    private function toReview(EventTicketOrder $order, string $reason, string $source, array &$afterCommit): string
    {
        $moved = $this->transition(
            $order,
            [
                EventTicketOrder::STATUS_PENDING_PAYMENT,
                EventTicketOrder::STATUS_CANCELLED,
                EventTicketOrder::STATUS_FAILED,
            ],
            EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED,
            ['review_reason' => $reason]
        );

        if (! $moved) {
            return self::NOOP;
        }

        Log::warning('[TicketPaymentOutcome] payment received but order needs review', [
            'orderNumber' => $order->order_number,
            'reason' => $reason,
            'source' => $source,
        ]);
        $afterCommit[] = new EventTicketOrderNeedsReview($order->fresh(), $reason, $source);

        return self::REVIEW;
    }

    /**
     * Compares what Lenco says was paid with the order total (never the client).
     * Any difference, over- or under-payment, is a mismatch.
     *
     * @param  array<string, mixed>  $lencoResult
     */
    private function amountMatches(EventTicketOrder $order, array $lencoResult): bool
    {
        $amount = $lencoResult['amount'] ?? null;
        if ($amount === null || $amount === '') {
            Log::warning('[TicketPaymentOutcome] gateway result had no amount; cannot verify', ['orderNumber' => $order->order_number]);

            return true;
        }

        $currency = strtoupper((string) ($lencoResult['currency'] ?? 'ZMW'));

        return $currency === 'ZMW'
            && (int) round((float) $amount * 100) === (int) round((float) $order->total * 100);
    }

    /**
     * Single conditional UPDATE: succeeds only if the row is still in one of
     * the allowed `from` states, so illegal transitions cannot happen even
     * with a stale in-memory model.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(EventTicketOrder $order, array $from, string $to, array $extra = []): bool
    {
        return EventTicketOrder::query()
            ->whereKey($order->id)
            ->whereIn('status', $from)
            ->update(array_merge($extra, ['status' => $to, 'updated_at' => now()])) === 1;
    }

    /**
     * Used by tests / callers needing a parsed timestamp for completedAt.
     */
    public static function parseTimestamp(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
