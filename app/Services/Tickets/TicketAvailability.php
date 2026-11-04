<?php

namespace App\Services\Tickets;

use App\Models\Event;
use App\Models\EventTicketOrder;
use LogicException;

/**
 * Decides whether tickets can be bought for an event right now.
 *
 * Capacity and sales availability are separate conditions, and every one is
 * checked here (sales switch, event status, sales window, event date, order
 * size, remaining capacity). The order endpoint is the authority; a "sold out"
 * message in the browser is only a convenience.
 *
 * To reserve tickets without overbooking, the caller must run in one database
 * transaction: {@see lockEvent()} first, then {@see check()} on the locked row,
 * then create the order. The lock serialises concurrent buyers per event.
 */
class TicketAvailability
{
    /**
     * Locks the event row for the rest of the current transaction.
     * Must be called inside DB::transaction().
     */
    public function lockEvent(string $eventId): Event
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) {
            throw new LogicException('lockEvent() must be called inside a database transaction.');
        }

        return Event::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
    }

    public function check(Event $event, int $quantity = 1): AvailabilityResult
    {
        if ($event->registration_type !== 'paid'
            || $event->ticket_price_member === null
            || $event->ticket_price_non_member === null) {
            return new AvailabilityResult(AvailabilityResult::NOT_TICKETED);
        }

        if (($event->ticket_status ?? 'scheduled') === 'cancelled') {
            return new AvailabilityResult(AvailabilityResult::CANCELLED);
        }

        if (! $event->ticket_sales_open) {
            return new AvailabilityResult(AvailabilityResult::SALES_CLOSED);
        }

        if ($event->registration_open !== null && $event->registration_open->isFuture()) {
            return new AvailabilityResult(AvailabilityResult::NOT_YET_OPEN);
        }

        if ($event->registration_close !== null && $event->registration_close->isPast()) {
            return new AvailabilityResult(AvailabilityResult::DEADLINE_PASSED);
        }

        if ($event->event_date !== null && $event->event_date->isPast()) {
            return new AvailabilityResult(AvailabilityResult::EVENT_OVER);
        }

        if ($quantity < 1) {
            return new AvailabilityResult(AvailabilityResult::INVALID_QUANTITY);
        }

        if ($quantity > (int) config('tickets.max_per_order', 10)) {
            return new AvailabilityResult(AvailabilityResult::OVER_ORDER_LIMIT);
        }

        $remaining = $this->remainingCapacity($event);
        if ($remaining !== null) {
            if ($remaining < 1) {
                return new AvailabilityResult(AvailabilityResult::SOLD_OUT, 0);
            }
            if ($quantity > $remaining) {
                return new AvailabilityResult(AvailabilityResult::ONLY_N_LEFT, $remaining);
            }
        }

        return new AvailabilityResult(AvailabilityResult::OK, $remaining);
    }

    /**
     * Tickets still available, or null when capacity is unlimited.
     */
    public function remainingCapacity(Event $event): ?int
    {
        if ($event->ticket_capacity === null) {
            return null;
        }

        return max(0, (int) $event->ticket_capacity - $this->committedTickets((string) $event->id));
    }

    /**
     * Tickets holding capacity: paid orders, orders held for payment review
     * (money received, unresolved), and pending orders still inside their
     * reservation window. An expired pending order stops counting immediately,
     * before the poller has formally cancelled it.
     */
    public function committedTickets(string $eventId): int
    {
        return (int) EventTicketOrder::query()
            ->where('event_id', $eventId)
            ->where(function ($q): void {
                $q->whereIn('status', [
                    EventTicketOrder::STATUS_PAID,
                    EventTicketOrder::STATUS_PAYMENT_REVIEW_REQUIRED,
                ])->orWhere(function ($q2): void {
                    $q2->where('status', EventTicketOrder::STATUS_PENDING_PAYMENT)
                        ->where('expires_at', '>', now());
                });
            })
            ->sum('quantity');
    }
}
